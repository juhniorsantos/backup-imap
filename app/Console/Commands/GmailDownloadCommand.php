<?php

namespace App\Console\Commands;

use App\Services\GmailImapDownloader;
use Illuminate\Console\Command;

class GmailDownloadCommand extends Command
{
    protected $signature = 'gmail:download';

    protected $description = 'Command description';

    public function handle(): void
    {
        $gmail_username = 'junior@lemitti.com';
        $gmail_password = env('GMAIL_PASS'); // 16 caracteres sem espaços

// Diretório onde salvar os emails (será criado se não existir)
        $download_directory = storage_path($gmail_username . '/gmail_backup/' );

// Verificar se a extensão IMAP está habilitada
        if (!extension_loaded('imap')) {
            die("ERRO: Extensão PHP IMAP não está habilitada!\n" .
                "No Ubuntu/Debian: sudo apt-get install php-imap\n" .
                "No CentOS/RHEL: sudo yum install php-imap\n" .
                "Depois reinicie o Apache/Nginx\n");
        }

// Executar o download
        echo "=== GMAIL IMAP EMAIL DOWNLOADER ===\n";
        echo "Iniciando backup de: $gmail_username\n";
        echo "Destino: $download_directory\n\n";

        $startTime = time();
        $downloader = new GmailImapDownloader($gmail_username, $gmail_password, $download_directory);

        $downloader->downloadAllEmails();
        $downloader->showStats();

        $endTime = time();
        $duration = $endTime - $startTime;
        echo 'Tempo total: '.gmdate('H:i:s', $duration)."\n";
    }
}
