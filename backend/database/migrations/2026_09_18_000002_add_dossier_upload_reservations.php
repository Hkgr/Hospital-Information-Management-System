<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_attachment_uploads', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('facility_id');
            $t->unsignedBigInteger('dossier_id');
            $t->unsignedBigInteger('visit_id');
            $t->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $t->uuid('request_id');
            $t->string('title', 200);
            $t->string('original_filename', 200);
            $t->string('state', 12)->default('pending');
            $t->dateTime('expires_at');
            $t->foreignId('attachment_id')->nullable()->constrained('visit_attachments')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['facility_id', 'uploaded_by', 'request_id'], 'attachment_upload_request');
            $t->index(['visit_id', 'state', 'expires_at'], 'attachment_upload_pending');
            $t->foreign(['visit_id', 'dossier_id', 'facility_id'], 'attachment_upload_scope')->references(['id', 'dossier_id', 'facility_id'])->on('visits')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE visit_attachment_uploads ADD CONSTRAINT attachment_upload_state CHECK (state IN ('pending','complete','cancelled'))");
    }

    public function down(): void
    {
        if (DB::table('visit_attachment_uploads')->exists()) {
            throw new RuntimeException('Upload reservation rollback refused: preserve saved workflow records.');
        }
        Schema::drop('visit_attachment_uploads');
    }
};
