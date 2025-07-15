<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Mail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Exception;

class ImapDownloadCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'imap:download {account_id? : ID da conta para baixar emails} {--limit=100 : Limite de emails por execução}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Baixa os emails pendentes das contas IMAP';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $accountId = $this->argument('account_id');
        $limit = $this->option('limit') ?? 15000;

        if ($accountId) {
            $accounts = Account::where('id', $accountId)->get();
        } else {
            $accounts = Account::whereNull('completed')->get();
        }

        foreach ($accounts as $account) {
            $this->info("Baixando emails da conta: {$account->user}");

            try {
                $this->downloadAccountEmails($account, $limit);

                // Verificar se todos os emails foram baixados
                $pendingCount = $account->mails()->where('downloaded', false)->count();

                if ($pendingCount == 0) {
                    $account->update(['completed' => now()]);
                    $this->info("Conta {$account->user} completamente baixada!");
                } else {
                    $this->info("Conta {$account->user}: ainda restam {$pendingCount} emails para baixar.");
                }

            } catch (Exception $e) {
                $this->error("Erro ao baixar emails da conta {$account->user}: " . $e->getMessage());
            }
        }
    }

    private function downloadAccountEmails(Account $account, $limit)
    {
        $host = '{imap.gmail.com:993/imap/ssl}';

        $connection = imap_open($host, $account->user, $account->password);

        if (!$connection) {
            throw new Exception("Falha ao conectar: " . imap_last_error());
        }

        try {
            // Buscar emails pendentes agrupados por pasta
            $pendingMails = $account->mails()
                ->where('downloaded', false)
                ->whereNotNull('folder')
                ->orderBy('folder')
                ->limit($limit)
                ->get()
                ->groupBy('folder');

            $totalPending = $pendingMails->flatten()->count();
            $this->info("  Emails pendentes: " . $totalPending);

            foreach ($pendingMails as $folder => $mails) {
                $this->info("  Processando pasta: " . str_replace($host, '', $folder));
                
                // Abrir a pasta
                imap_reopen($connection, $folder);
                
                $bar = $this->output->createProgressBar($mails->count());
                
                foreach ($mails as $mail) {
                    try {
                        $this->downloadSingleEmail($connection, $account, $mail);
                        $bar->advance();
                    } catch (Exception $e) {
                        $this->error("\n  Erro ao baixar email {$mail->uuid}: " . $e->getMessage());
                    }
                }
                
                $bar->finish();
                $this->newLine();
            }

        } finally {
            imap_close($connection);
        }
    }

    private function downloadSingleEmail($connection, Account $account, Mail $mail)
    {
        // Criar diretório para a conta se não existir
        $accountDir = 'emails/' . $this->sanitizeFilename($account->user);
        Storage::makeDirectory($accountDir);

        // Se temos o número da mensagem, usar diretamente
        if ($mail->message_number) {
            $messageNumber = $mail->message_number;
            
            // Verificar se o número ainda é válido
            $totalMessages = imap_num_msg($connection);
            if ($messageNumber > $totalMessages) {
                $mail->update(['downloaded' => true, 'filename' => 'ORPHAN']);
                throw new Exception("Mensagem não existe mais (número maior que total) - marcada como órfã");
            }
        } else {
            // Fallback: tentar buscar pelo Message-ID
            $messages = false;

            // Método 1: Buscar pelo Message-ID usando HEADER
            $searchCriteria = 'HEADER Message-ID "' . $mail->uuid . '"';
            $messages = @imap_search($connection, $searchCriteria);

            // Método 2: Se não encontrar, tentar sem os < >
            if (!$messages && (strpos($mail->uuid, '<') === 0)) {
                $cleanId = trim($mail->uuid, '<>');
                $searchCriteria = 'HEADER Message-ID "' . $cleanId . '"';
                $messages = @imap_search($connection, $searchCriteria);
            }

            // Método 3: Buscar no texto completo
            if (!$messages) {
                $searchCriteria = 'TEXT "' . str_replace('"', '', $mail->uuid) . '"';
                $messages = @imap_search($connection, $searchCriteria);
            }

            if (!$messages || count($messages) == 0) {
                $mail->update(['downloaded' => true, 'filename' => 'ORPHAN']);
                throw new Exception("Email não encontrado no servidor - marcado como órfão");
            }

            $messageNumber = $messages[0];
        }

        // Obter cabeçalho para informações do arquivo
        $header = imap_headerinfo($connection, $messageNumber);

        if ($header === false) {
            $date = date('Y-m-d_H-i-s');
            $subject = 'Email_Sem_Cabecalho';
        } else {
            $date = date('Y-m-d_H-i-s', $header->udate);
            $subject = isset($header->subject) ? $header->subject : 'Sem_Assunto';
            $subject = $this->sanitizeFilename($subject);
            $subject = substr($subject, 0, 50);
        }

        $filename = "{$date}_{$messageNumber}_{$subject}.eml";
        $filepath = $accountDir . '/' . $filename;

        // Obter email completo em formato raw
        $emailRaw = imap_fetchheader($connection, $messageNumber, FT_PREFETCHTEXT) .
                    imap_body($connection, $messageNumber);

        // Salvar arquivo
        Storage::put($filepath, $emailRaw);

        // Atualizar registro no banco
        $mail->update([
            'downloaded' => true,
            'filename' => $filepath
        ]);
    }

    private function sanitizeFilename($filename)
    {
        // Remove caracteres especiais do nome do arquivo
        $filename = preg_replace('/[^a-zA-Z0-9\-\_\.]/', '_', $filename);
        $filename = preg_replace('/_+/', '_', $filename); // Remove underscores múltiplos
        return trim($filename, '_');
    }
}
