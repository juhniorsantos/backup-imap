<?php

namespace App\Console\Commands;

use App\Models\Account;
use Illuminate\Console\Command;

class AddAccountCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'account:add {user : Email da conta} {password : Senha da conta}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Adiciona uma nova conta IMAP para sincronização';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $user = $this->argument('user');
        $password = $this->argument('password');
        
        // Verificar se a conta já existe
        if (Account::where('user', $user)->exists()) {
            $this->error("A conta {$user} já existe no sistema!");
            return 1;
        }
        
        // Testar a conexão antes de salvar
        $this->info("Testando conexão com a conta {$user}...");
        
        $host = '{imap.gmail.com:993/imap/ssl}';
        $connection = @imap_open($host, $user, $password);
        
        if (!$connection) {
            $this->error("Falha ao conectar na conta {$user}: " . imap_last_error());
            return 1;
        }
        
        imap_close($connection);
        
        // Criar a conta
        $account = Account::create([
            'user' => $user,
            'password' => $password
        ]);
        
        $this->info("Conta {$user} adicionada com sucesso! ID: {$account->id}");
        
        if ($this->confirm('Deseja sincronizar os UUIDs agora?')) {
            $this->call('imap:sync', ['account_id' => $account->id]);
        }
        
        return 0;
    }
}