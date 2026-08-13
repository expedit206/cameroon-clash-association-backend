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
        // 1. Mise à jour de bet_markets pour intégrer le Rule Engine AST
        Schema::table('bet_markets', function (Blueprint $table) {
            if (!Schema::hasColumn('bet_markets', 'title')) {
                $table->string('title')->nullable()->after('match_id');
            }
            if (!Schema::hasColumn('bet_markets', 'description')) {
                $table->text('description')->nullable()->after('title');
            }
            if (!Schema::hasColumn('bet_markets', 'category')) {
                $table->string('category', 50)->default('team')->after('description');
            }
            if (!Schema::hasColumn('bet_markets', 'rule_definition')) {
                $table->json('rule_definition')->nullable()->after('category');
            }
            if (!Schema::hasColumn('bet_markets', 'rule_version')) {
                $table->integer('rule_version')->default(1)->after('rule_definition');
            }
            if (!Schema::hasColumn('bet_markets', 'evaluation_snapshot')) {
                $table->json('evaluation_snapshot')->nullable()->after('rule_version');
            }
            if (!Schema::hasColumn('bet_markets', 'winning_side')) {
                $table->string('winning_side', 10)->nullable()->after('evaluation_snapshot');
            }
        });

        // 2. Mise à jour de bet_tickets pour le choix de side (YES / NO) et le versioning de règle
        Schema::table('bet_tickets', function (Blueprint $table) {
            if (!Schema::hasColumn('bet_tickets', 'side')) {
                $table->string('side', 10)->default('YES')->after('creator_option_id');
            }
            if (!Schema::hasColumn('bet_tickets', 'rule_version')) {
                $table->integer('rule_version')->default(1)->after('side');
            }
        });

        // 3. Création de la table market_templates
        if (!Schema::hasTable('market_templates')) {
            Schema::create('market_templates', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('category', 50)->default('team');
                $table->json('rule_template');
                $table->timestamps();
            });
        }

        // 4. Création de la table clash_bet_audits
        if (!Schema::hasTable('clash_bet_audits')) {
            Schema::create('clash_bet_audits', function (Blueprint $table) {
                $table->id();
                $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('event_type', 50);
                $table->foreignId('market_id')->nullable()->constrained('bet_markets')->nullOnDelete();
                $table->foreignId('ticket_id')->nullable()->constrained('bet_tickets')->nullOnDelete();
                $table->json('payload')->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clash_bet_audits');
        Schema::dropIfExists('market_templates');

        Schema::table('bet_tickets', function (Blueprint $table) {
            $table->dropColumn(['side', 'rule_version']);
        });

        Schema::table('bet_markets', function (Blueprint $table) {
            $table->dropColumn([
                'title',
                'description',
                'category',
                'rule_definition',
                'rule_version',
                'evaluation_snapshot',
                'winning_side',
            ]);
        });
    }
};
