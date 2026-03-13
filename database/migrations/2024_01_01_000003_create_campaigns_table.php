<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('subject');
            $table->text('body');
            $table->foreignId('contact_list_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['draft', 'sending', 'sent'])->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamps();
            $table->index('status');
            $table->index('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
