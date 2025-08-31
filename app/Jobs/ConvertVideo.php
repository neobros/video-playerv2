<?php

namespace App\Jobs;

use App\Models\Video;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class ConvertVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600; // 1h per job
    public function __construct(public Video $video) {}

    public function handle(): void
    {
        $video = $this->video->refresh();
        $video->update(['status' => 'processing', 'progress' => 5]);

        $disk = Storage::disk('public');

        // folder: videos/{id}
        $baseDir = "videos/{$video->id}";
        $origPath = $video->original_path; // already inside 'public' disk
        $absOrig = $disk->path($origPath);

        // HLS output dirs
        $hls480Dir = "$baseDir/hls_480p";
        $hls360Dir = "$baseDir/hls_360p";
        $thumbDir  = "$baseDir/thumbnails";

        foreach ([$hls480Dir, $hls360Dir, $thumbDir] as $d) {
            if (!$disk->exists($d)) $disk->makeDirectory($d);
        }

        // absolute paths for ffmpeg IO
        $abs480Dir = $disk->path($hls480Dir);
        $abs360Dir = $disk->path($hls360Dir);
        $absThumb  = $disk->path($thumbDir . '/thumb.jpg');

        // 480p
        $video->update(['progress' => 20]);
        $cmd480 = [
            'ffmpeg','-y','-i', $absOrig,
            '-vf','scale=-2:480',
            '-c:v','h264','-profile:v','main','-preset','veryfast','-g','48','-keyint_min','48','-sc_threshold','0',
            '-b:v','800k','-maxrate','856k','-bufsize','1200k',
            '-c:a','aac','-ar','48000','-b:a','128k',
            '-hls_time','4','-hls_segment_type','mpegts',
            '-hls_segment_filename', $abs480Dir.'/seg_%03d.ts',
            '-hls_playlist_type','vod',
            $abs480Dir.'/index.m3u8'
        ];
        $this->run($cmd480, 600); // 10 min

        // 360p
        $video->update(['progress' => 50]);
        $cmd360 = [
            'ffmpeg','-y','-i', $absOrig,
            '-vf','scale=-2:360',
            '-c:v','h264','-profile:v','main','-preset','veryfast','-g','48','-keyint_min','48','-sc_threshold','0',
            '-b:v','500k','-maxrate','535k','-bufsize','750k',
            '-c:a','aac','-ar','48000','-b:a','96k',
            '-hls_time','4','-hls_segment_type','mpegts',
            '-hls_segment_filename', $abs360Dir.'/seg_%03d.ts',
            '-hls_playlist_type','vod',
            $abs360Dir.'/index.m3u8'
        ];
        $this->run($cmd360, 600);

        // Thumbnail (@ ~5s; fallback frame 1 if short)
        $video->update(['progress' => 70]);
        $cmdThumb = ['ffmpeg','-y','-ss','00:00:05','-i',$absOrig,'-vframes','1','-vf','scale=640:-1',$absThumb];
        $this->run($cmdThumb, 60);

        // Master playlist
        $video->update(['progress' => 85]);
        $masterRel = "$baseDir/master.m3u8";
        $masterAbs = $disk->path($masterRel);
        $master = <<<M3U8
#EXTM3U
#EXT-X-VERSION:3
#EXT-X-INDEPENDENT-SEGMENTS
#EXT-X-STREAM-INF:BANDWIDTH=1200000,AVERAGE-BANDWIDTH=1000000,RESOLUTION=854x480,CODECS="avc1.64001F,mp4a.40.2"
hls_480p/index.m3u8
#EXT-X-STREAM-INF:BANDWIDTH=750000,AVERAGE-BANDWIDTH=600000,RESOLUTION=640x360,CODECS="avc1.64001E,mp4a.40.2"
hls_360p/index.m3u8
M3U8;
        file_put_contents($masterAbs, $master);

        // Save paths (relative to public disk root)
        $video->update([
            'hls_master_path' => $masterRel,
            'hls_480p_path'   => "$hls480Dir/index.m3u8",
            'hls_360p_path'   => "$hls360Dir/index.m3u8",
            'thumbnail_path'  => "$thumbDir/thumb.jpg",
            'status'          => 'ready',
            'progress'        => 100,
        ]);
    }

    private function run(array $cmd, int $timeout = 300): void
    {
        $proc = new Process($cmd, null, null, null, $timeout);
        $proc->run();
        if (!$proc->isSuccessful()) {
            throw new Exception("FFmpeg failed: " . $proc->getErrorOutput());
        }
    }

    public function failed(Exception $e): void
    {
        $this->video->update(['status' => 'failed', 'progress' => 0]);
    }
}
