<?php

namespace App\Services;

/**
 * Gmail IMAP Email Downloader
 *
 * Este script conecta ao Gmail via IMAP, lista todas as pastas
 * e baixa todos os emails salvando-os como arquivos .eml
 *
 * Requisitos:
 * - PHP com extensão IMAP habilitada
 * - Senha de app do Gmail (não a senha normal)
 * - IMAP habilitado na conta Gmail
 */

class GmailImapDownloader
{
    private $username;
    private $password;
    private $server;
    private $port;
    private $baseDir;
    private $connection;

    public function __construct($username, $password, $baseDir = 'emails')
    {
        $this->username = $username;
        $this->password = $password;
        $this->server = 'imap.gmail.com';
        $this->port = 993;
        $this->baseDir = $baseDir;

        // Criar diretório base se não existir
        if (!is_dir($this->baseDir)) {
            mkdir($this->baseDir, 0755, true);
        }
    }

    /**
     * Conecta ao servidor IMAP do Gmail
     */
    public function connect()
    {
        $connectionString = "{{$this->server}:{$this->port}/imap/ssl}";

        echo "Conectando ao Gmail IMAP...\n";
        $this->connection = imap_open($connectionString, $this->username, $this->password);

        if (!$this->connection) {
            throw new Exception('Erro ao conectar: '.imap_last_error());
        }

        echo "Conectado com sucesso!\n";
        return true;
    }

    /**
     * Lista todas as pastas disponíveis
     */
    public function getFolders()
    {
        $folders = imap_list($this->connection, "{{$this->server}:{$this->port}/imap/ssl}", '*');

        if (!$folders) {
            throw new Exception('Erro ao listar pastas: '.imap_last_error());
        }

        $folderNames = [];
        foreach ($folders as $folder) {
            // Remove o prefixo do servidor para obter apenas o nome da pasta
            $folderName = str_replace("{{$this->server}:{$this->port}/imap/ssl}", '', $folder);
            $folderNames[] = $folderName;
        }

        return $folderNames;
    }

    /**
     * Sanitiza nome de arquivo/diretório
     */
    private function sanitizeFilename($filename)
    {
        // Remove caracteres inválidos para nomes de arquivo
        $filename = preg_replace('#[<>:"/|?*]#', '_', $filename);
        $filename = trim($filename);

        // Remove caracteres especiais adicionais
        $filename = str_replace(['[Gmail]/', '[Gmail]'], '', $filename);
        $filename = trim($filename, '/');

        return $filename;
    }

    /**
     * Cria estrutura de diretórios baseada no nome da pasta
     */
    private function createFolderDirectory($folderName)
    {
        $safeFolderName = $this->sanitizeFilename($folderName);
        $folderPath = $this->baseDir.'/'.$safeFolderName;

        if (!is_dir($folderPath)) {
            mkdir($folderPath, 0755, true);
        }

        return $folderPath;
    }

    /**
     * Baixa todos os emails de uma pasta específica
     */
    public function downloadEmailsFromFolder($folderName)
    {
        echo "\n=== Processando pasta: $folderName ===\n";

        // Reconecta especificando a pasta
        imap_close($this->connection);
        $connectionString = "{{$this->server}:{$this->port}/imap/ssl}$folderName";
        $this->connection = imap_open($connectionString, $this->username, $this->password);

        if (!$this->connection) {
            echo "Erro ao abrir pasta $folderName: ".imap_last_error()."\n";
            return false;
        }

        // Criar diretório para esta pasta
        $folderPath = $this->createFolderDirectory($folderName);

        // Obter número total de emails na pasta
        $totalEmails = imap_num_msg($this->connection);
        echo "Total de emails na pasta: $totalEmails\n";

        if ($totalEmails == 0) {
            echo "Pasta vazia, pulando...\n";
            return true;
        }

        // Baixar cada email
        for ($i = 1; $i <= $totalEmails; $i++) {
            try {
                $this->downloadSingleEmail($i, $folderPath);

                // Mostrar progresso a cada 10 emails
                if ($i % 10 == 0 || $i == $totalEmails) {
                    echo "Progresso: $i/$totalEmails emails baixados\n";
                }

            } catch (Exception $e) {
                echo "Erro ao baixar email $i: ".$e->getMessage()."\n";
                continue;
            }
        }

        echo "Pasta $folderName concluída!\n";
        return true;
    }

    /**
     * Baixa um email específico e salva como .eml
     */
    private function downloadSingleEmail($messageNumber, $folderPath)
    {
        // Obter cabeçalho do email para gerar nome do arquivo
        $header = imap_headerinfo($this->connection, $messageNumber);

        // Verificar se o cabeçalho foi obtido com sucesso
        if ($header === false) {
            // Se falhar, usar valores padrão
            $date = date('Y-m-d_H-i-s');
            $subject = 'Email_Sem_Cabecalho';
            $filename = "{$date}_{$messageNumber}_{$subject}.eml";
        } else {
            // Gerar nome do arquivo baseado na data e assunto
            $date = date('Y-m-d_H-i-s', $header->udate);
            $subject = isset($header->subject) ? $header->subject : 'Sem_Assunto';
            $subject = $this->sanitizeFilename($subject);
            $subject = substr($subject, 0, 50); // Limitar tamanho do nome
            $filename = "{$date}_{$messageNumber}_{$subject}.eml";
        }

        $filepath = $folderPath.'/'.$filename;

        // Verificar se arquivo já existe
        if (file_exists($filepath)) {
            return true; // Pular se já existe
        }

        // Obter email completo em formato raw (RFC822)
        $emailRaw = imap_fetchheader($this->connection, $messageNumber, FT_PREFETCHTEXT).
            imap_body($this->connection, $messageNumber);

        // Salvar como arquivo .eml
        $result = file_put_contents($filepath, $emailRaw);

        if ($result === false) {
            throw new Exception("Erro ao salvar arquivo: $filepath");
        }

        return true;
    }

    /**
     * Processa todas as pastas e baixa todos os emails
     */
    public function downloadAllEmails()
    {
        try {
            $this->connect();

            echo "Listando pastas disponíveis...\n";
            $folders = $this->getFolders();

            echo "Pastas encontradas:\n";
            foreach ($folders as $folder) {
                echo "- $folder\n";
            }

            echo "\nIniciando download dos emails...\n";

            foreach ($folders as $folder) {
                $this->downloadEmailsFromFolder($folder);
            }

            echo "\n=== DOWNLOAD COMPLETO! ===\n";
            echo "Todos os emails foram salvos em: {$this->baseDir}\n";

        } catch (Exception $e) {
            echo 'ERRO: '.$e->getMessage()."\n";
        } finally {
            if ($this->connection) {
                imap_close($this->connection);
            }
        }
    }

    /**
     * Mostra estatísticas do download
     */
    public function showStats()
    {
        if (!is_dir($this->baseDir)) {
            echo "Diretório base não encontrado.\n";
            return;
        }

        $totalFiles = 0;
        $totalSize = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->baseDir)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'eml') {
                $totalFiles++;
                $totalSize += $file->getSize();
            }
        }

        echo "\n=== ESTATÍSTICAS ===\n";
        echo "Total de emails baixados: $totalFiles\n";
        echo 'Tamanho total: '.$this->formatBytes($totalSize)."\n";
        echo "Localização: {$this->baseDir}\n";
    }

    /**
     * Formata bytes em formato legível
     */
    private function formatBytes($bytes, $precision = 2)
    {
        $units = array('B', 'KB', 'MB', 'GB', 'TB');

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, $precision).' '.$units[$i];
    }
}
