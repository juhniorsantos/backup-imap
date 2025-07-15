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
        $limit = $this->option('limit');
        
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
            // Buscar emails pendentes
            $pendingMails = $account->mails()
                ->where('downloaded', false)
                ->limit($limit)
                ->get();
            
            $this->info("  Emails pendentes: " . $pendingMails->count());
            
            $bar = $this->output->createProgressBar($pendingMails->count());
            
            foreach ($pendingMails as $mail) {
                try {
                    $this->downloadSingleEmail($connection, $account, $mail);
                    $bar->advance();
                } catch (Exception $e) {
                    $this->error("\n  Erro ao baixar email {$mail->uuid}: " . $e->getMessage());
                }
            }
            
            $bar->finish();
            $this->newLine();
            
        } finally {
            imap_close($connection);
        }
    }

    private function downloadSingleEmail($connection, Account $account, Mail $mail)
    {
        // Criar diretório para a conta se não existir
        $accountDir = 'emails/' . $this->sanitizeFilename($account->user);
        Storage::makeDirectory($accountDir);
        
        // Buscar o email pelo Message-ID
        $searchCriteria = 'TEXT "' . str_replace('"', '', $mail->uuid) . '"';
        $messages = imap_search($connection, $searchCriteria);
        
        if (!$messages || count($messages) == 0) {
            throw new Exception("Email não encontrado no servidor");
        }
        
        // Pegar o primeiro resultado (deve ser único)
        $messageNumber = $messages[0];
        
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