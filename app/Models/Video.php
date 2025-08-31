<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Video extends Model
{
    protected $fillable = [
        'title','original_path','hls_master_path','hls_480p_path','hls_360p_path','thumbnail_path','duration','status','progress'
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
    public function getThumbnailUrlAttribute(): ?string {
        return $this->thumbnail_path ? Storage::url($this->thumbnail_path) : null;
    }
}
