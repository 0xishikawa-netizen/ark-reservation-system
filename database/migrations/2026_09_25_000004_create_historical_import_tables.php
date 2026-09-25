<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historical_import_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('source_filename', 255);
            $table->string('source_disk', 30)->default('local');
            $table->string('source_path', 500);
            $table->char('source_sha256', 64)->unique();
            $table->string('source_format', 10);
            $table->string('status', 24)->default('staged');
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('historical_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('historical_import_batches')->restrictOnDelete();
            $table->string('sheet_name', 100);
            $table->unsignedInteger('source_row');
            $table->char('row_hmac', 64);
            $table->text('source_identifier_ciphertext')->nullable();
            $table->char('source_identifier_hmac', 64)->nullable();
            $table->string('record_type', 24)->nullable();
            $table->string('validation_status', 24)->default('pending');
            $table->json('validation_errors')->nullable();
            $table->foreignId('matched_customer_id')->nullable()->constrained('customers', 'user_id')->nullOnDelete();
            $table->string('match_method', 24)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'sheet_name', 'source_row']);
            $table->index(['batch_id', 'validation_status']);
        });

        Schema::create('historical_import_cells', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('row_id')->constrained('historical_import_rows')->restrictOnDelete();
            $table->string('cell_coordinate', 12);
            $table->text('value_ciphertext')->nullable();
            $table->char('value_hmac', 64);
            $table->unique(['row_id', 'cell_coordinate']);
        });

        Schema::create('historical_metric_values', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('batch_id')->constrained('historical_import_batches')->restrictOnDelete();
            $table->foreignId('source_row_id')->unique()->constrained('historical_import_rows')->restrictOnDelete();
            $table->string('metric_code', 60);
            $table->date('period_start');
            $table->date('period_end');
            $table->bigInteger('value_integer');
            $table->text('source_identifier_ciphertext')->nullable();
            $table->timestamp('imported_at');
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamps();
            $table->index(['metric_code', 'period_start', 'period_end'], 'hist_metric_period_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('historical_metric_values');
        Schema::dropIfExists('historical_import_cells');
        Schema::dropIfExists('historical_import_rows');
        Schema::dropIfExists('historical_import_batches');
    }
};
