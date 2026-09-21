<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Video extends Model
{
    public $incrementing = false;   // disable auto-increment
    protected $keyType = 'string';  // tell Eloquent primary key is string

    protected $fillable = [
        'title','original_path','hls_master_path','hls_480p_path','hls_360p_path','hls_240p_path','thumbnail_path','duration','status','progress','year','class_id','id'
    ];

    protected $appends = ['original_url','master_url','p480_url','p360_url','thumbnail_url'];

    public function getOriginalUrlAttribute(): ?string {
        return $this->original_path ? Storage::url($this->original_path) : null;
    }
    public function getMasterUrlAttribute(): ?string {
        return $this->hls_master_path ? Storage::url($this->hls_master_path) : null;
    }
    public function getP480UrlAttribute(): ?string {
        return $this->hls_480p_path ? Storage::url($this->hls_480p_path) : null;
    }
    public function getP360UrlAttribute(): ?string {
        return $this->hls_360p_path ? Storage::url($this->hls_360p_path) : null;
    }
    public function getP240UrlAttribute(): ?string {
        return $this->hls_240p_path ? Storage::url($this->hls_240p_path) : null;
    }
    public function getThumbnailUrlAttribute(): ?string {
        return $this->thumbnail_path ? Storage::url($this->thumbnail_path) : null;
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->id)) {
                do {
                    // 20-char random string (alphanumeric)
                    $id = strtoupper(bin2hex(random_bytes(10))); // 20 hex chars
                } while (self::where('id', $id)->exists());

                $model->id = $id;
            }
        });
    }
}
