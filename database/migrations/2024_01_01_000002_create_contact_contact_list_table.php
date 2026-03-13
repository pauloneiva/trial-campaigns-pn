<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_contact_list', function (Blueprint $table) {
            $table->foreignId('contact_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_list_id')->constrained()->cascadeOnDelete();
            $table->unique(['contact_id', 'contact_list_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_contact_list');
    }
};
