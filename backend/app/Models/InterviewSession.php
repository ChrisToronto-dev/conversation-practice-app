<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InterviewSession extends Model
{
    protected $fillable = [
        'title',
        'user_key_hash',
        'topic',
        'fluency_score',
        'grammar_score',
        'overall_score',
        'feedback',
        'feedback_generated_at',
    ];

    protected $casts = [
        'fluency_score' => 'float',
        'grammar_score' => 'float',
        'overall_score' => 'float',
        'feedback_generated_at' => 'datetime',
    ];

    public function logs()
    {
        return $this->hasMany(InterviewSessionLog::class);
    }
}
