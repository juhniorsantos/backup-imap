<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('mails', function (Blueprint $table) {
            $table->integer('message_number')->nullable()->after('uuid');
            $table->string('folder')->nullable()->after('message_number');
            $table->index(['account_id', 'folder', 'message_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('mails', function (Blueprint $table) {
            $table->dropIndex(['account_id', 'folder', 'message_number']);
            $table->dropColumn(['message_number', 'folder']);
        });
    }
};
