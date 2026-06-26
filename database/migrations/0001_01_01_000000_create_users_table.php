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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('tag_coc', 20)->unique(); // Tag joueur CoC (#XXXXX)
            $table->string('name'); // Nom ingame récupéré via API
            $table->string('password');
            $table->enum('role', ['player', 'captain', 'referee', 'admin'])->default('player');
            $table->enum('status', ['pending', 'validated', 'rejected'])->default('pending'); // État du compte
            $table->string('screenshot_proof')->nullable(); // Preuve d'appartenance CoC
            $table->tinyInteger('hdv_level'); // Niveau HDV (14 à 18)
            $table->string('current_clan_tag', 20)->nullable(); // Tag du clan actuel
            $table->string('phone_whatsapp', 20)->nullable(); // Numéro pour notifications
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('tag_coc')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
