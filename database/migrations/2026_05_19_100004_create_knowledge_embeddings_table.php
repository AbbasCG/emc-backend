<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RAG foundation — stores text chunks and their embedding vectors.
 *
 * Vector column is stored as JSON for now (portable across all databases).
 * When pgvector or Pinecone is available, migrate to native vector type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_embeddings', function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('embeddable'); // knowledge_articles, documents, lessons…
            $table->text('chunk_text');           // the raw text chunk
            $table->json('embedding')->nullable(); // float[] — vector placeholder
            $table->json('metadata')->nullable();  // {'source': 'knowledge', 'page': 1}
            $table->string('model')->nullable();   // embedding model used
            $table->timestamps();
            // nullableMorphs() creates the composite index automatically
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_embeddings');
    }
};
