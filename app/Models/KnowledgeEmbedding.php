<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class KnowledgeEmbedding extends Model
{
    protected $fillable = ['embeddable_type', 'embeddable_id', 'chunk_text', 'embedding', 'metadata', 'model'];

    protected $casts = [
        'embedding' => 'array',
        'metadata'  => 'array',
    ];

    public function embeddable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Basic cosine similarity with another embedding vector.
     * Returns value in [-1, 1]; higher = more similar.
     */
    public function cosineSimilarity(array $other): float
    {
        $a = $this->embedding ?? [];
        if (empty($a) || empty($other) || count($a) !== count($other)) {
            return 0.0;
        }

        $dot   = 0;
        $normA = 0;
        $normB = 0;

        foreach ($a as $i => $val) {
            $dot   += $val * $other[$i];
            $normA += $val * $val;
            $normB += $other[$i] * $other[$i];
        }

        $denom = sqrt($normA) * sqrt($normB);

        return $denom > 0 ? $dot / $denom : 0.0;
    }
}
