<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MemoryMedia extends Model
{
    protected $table = 'memory_media';

    protected $fillable = ['file_path', 'media_type', 'mime_type', 'file_size', 'sort_order'];

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return ['file_size' => 'integer', 'sort_order' => 'integer'];
    }
}
