<?php

namespace App\Models;

use App\Models\Concerns\HasWorkflowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class GlJournal extends Model
{
    use HasWorkflowStatus, SoftDeletes;

    protected $table = 'gl_journals';

    protected $fillable = [
        'journal_no', 'journal_date', 'description', 'source_type', 'source_id',
        'status', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'journal_date' => 'date',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(GlJournalLine::class, 'gl_journal_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Posting otomatis dari approval transaksi app ini (status langsung "selesai", tidak lewat
     * draft->diajukan seperti JV manual milik app `gl`). Versi method ini identik di model
     * GlJournal milik app gl/ap/prc/inv — duplikasi sengaja, bukan dipanggil cross-app.
     */
    public static function postBalanced(array $header, array $lines): self
    {
        $journal = self::create($header + ['journal_no' => 'TEMP', 'status' => 'selesai']);
        $journal->update(['journal_no' => sprintf('JV-%s-%05d', $journal->journal_date->format('Ym'), $journal->id)]);
        $journal->lines()->createMany($lines);

        return $journal;
    }
}
