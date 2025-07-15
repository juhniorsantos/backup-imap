<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Mail;
use Illuminate\Console\Command;
use Exception;

class ImapSyncCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'imap:sync {account_id? : ID da conta para sincronizar}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sincroniza os UUIDs das mensagens IMAP com o banco de dados';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $accountId = $this->argument('account_id');

        if ($accountId) {
            $accounts = Account::where('id', $accountId)->get();
        } else {
            $accounts = Account::all();
        }

        foreach ($accounts as $account) {
            $this->info("Sincronizando conta: {$account->user}");

            try {
                $this->syncAccount($account);
                $this->info("Conta {$account->user} sincronizada com sucesso!");
            } catch (Exception $e) {
                $this->error("Erro ao sincronizar conta {$account->user}: " . $e->getMessage());
            }
        }
    }

    private function syncAccount(Account $account)
    {
        $host = '{imap.gmail.com:993/imap/ssl}';

        $connection = imap_open($host, $account->user, $account->password);

        if (!$connection) {
            throw new Exception("Falha ao conectar: " . imap_last_error());
        }

        try {
            $folders = imap_list($connection, $host, '*');

            foreach ($folders as $folder) {
                $folderName = str_replace($host, '', $folder);
                $this->info("  Processando pasta: $folderName");

                imap_reopen($connection, $folder);

                $messageCount = imap_num_msg($connection);
                $this->info("  Total de mensagens: $messageCount");

                $bar = $this->output->createProgressBar($messageCount);

                for ($i = 1; $i <= $messageCount; $i++) {
                    $header = imap_headerinfo($connection, $i);
                    
                    if ($header && isset($header->message_id)) {
                        $uuid = $header->message_id;

                        Mail::firstOrCreate(
                            [
                                'account_id' => $account->id,
                                'uuid' => $uuid
                            ],
                            [
                                'message_number' => $i,
                                'folder' => $folder,
                                'downloaded' => false
                            ]
                        );
                    } else {
                        // Se não tiver Message-ID, criar um único baseado na pasta e número
                        $uuid = "NO-MESSAGE-ID-{$folderName}-{$i}";
                        
                        Mail::firstOrCreate(
                            [
                                'account_id' => $account->id,
                                'uuid' => $uuid
                            ],
                            [
                                'message_number' => $i,
                                'folder' => $folder,
                                'downloaded' => false
                            ]
                        );
                    }

                    $bar->advance();
                }

                $bar->finish();
                $this->newLine();
            }

        } finally {
            imap_close($connection);
        }
    }
}
