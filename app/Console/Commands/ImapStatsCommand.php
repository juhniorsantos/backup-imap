<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\Mail;
use Illuminate\Console\Command;

class ImapStatsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'imap:stats';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Exibe estatísticas das contas e downloads';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('=== Estatísticas do Sistema de Backup IMAP ===');
        $this->newLine();
        
        // Estatísticas gerais
        $totalAccounts = Account::count();
        $completedAccounts = Account::whereNotNull('completed')->count();
        $totalMails = Mail::count();
        $downloadedMails = Mail::where('downloaded', true)->count();
        $orphanMails = Mail::where('filename', 'ORPHAN')->count();
        
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Total de Contas', $totalAccounts],
                ['Contas Completas', $completedAccounts],
                ['Total de Emails', $totalMails],
                ['Emails Baixados', $downloadedMails],
                ['Emails Órfãos', $orphanMails],
                ['Emails Pendentes', $totalMails - $downloadedMails],
            ]
        );
        
        $this->newLine();
        $this->info('=== Detalhes por Conta ===');
        
        $accounts = Account::withCount([
            'mails',
            'mails as downloaded_count' => function ($query) {
                $query->where('downloaded', true);
            },
            'mails as orphan_count' => function ($query) {
                $query->where('filename', 'ORPHAN');
            }
        ])->get();
        
        $tableData = [];
        foreach ($accounts as $account) {
            $pending = $account->mails_count - $account->downloaded_count;
            $percentage = $account->mails_count > 0 
                ? round(($account->downloaded_count / $account->mails_count) * 100, 2) 
                : 0;
                
            $tableData[] = [
                $account->id,
                $account->user,
                $account->mails_count,
                $account->downloaded_count,
                $pending,
                $account->orphan_count,
                $percentage . '%',
                $account->completed ? '✓' : '✗'
            ];
        }
        
        $this->table(
            ['ID', 'Conta', 'Total', 'Baixados', 'Pendentes', 'Órfãos', 'Progresso', 'Completa'],
            $tableData
        );
    }
}