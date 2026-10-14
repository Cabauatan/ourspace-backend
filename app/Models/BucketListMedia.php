<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BucketListMedia extends Model
{
    protected $table = 'bucket_list_media';

    protected $fillable = ['file_path', 'mime_type', 'file_size', 'sort_order'];

    protected $hidden = ['file_path'];

    protected function casts(): array
    {
        return ['file_size' => 'integer', 'sort_order' => 'integer'];
    }
}
