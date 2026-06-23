<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GlSetting extends Model
{
    protected $table = 'gl_settings';

    protected $fillable = ['key', 'gl_account_id', 'description'];

    public function glAccount(): BelongsTo
    {
        return $this->belongsTo(GlAccount::class);
    }
}
