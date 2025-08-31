<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Video;

class VideoApiController extends Controller
{
    public function show(Video $video)
    {
        return response()->json([
            'id'          => $video->id,
            'title'       => $video->title,
            'status'      => $video->status,
            'progress'    => $video->progress,
            'master_url'  => $video->master_url,
            'thumbnail'   => $video->thumbnail_url,
            'created_at'  => $video->created_at,
        ]);
    }
}
