<?php

namespace App\Jobs;

use App\Models\Video;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class ConvertVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3 * 3600; // allow long encodes

    private string $ffmpeg;
    private ?string $lastError = null;

    public function __construct(public Video $video) {}

    public function handle(): void
    {
        // If you created config/video.php as suggested before; otherwise hardcode '/usr/bin/ffmpeg'
        $this->ffmpeg = (string) (config('video.ffmpeg') ?? '/usr/bin/ffmpeg');

        $video = $this->video->refresh();
        $video->update([
            'status'        => 'processing',
            'progress'      => 1,
            'error_message' => null, // if you added this column
        ]);

        $disk = Storage::disk('public');

        // ---- Checks ---------------------------------------------------------
        if (!$this->checkFfmpeg()) {
            throw new Exception("ffmpeg not found or not executable at: {$this->ffmpeg}");
        }

        $baseDir = "videos/{$video->year}/{$video->id}";
        $origRel = $video->original_path; // path on 'public' disk
        $absOrig = $disk->path($origRel);

        if (!$disk->exists($origRel) || !is_file($absOrig)) {
            throw new Exception("Original file missing: {$absOrig}");
        }
        if (!is_readable($absOrig)) {
            throw new Exception("Original file not readable by queue user: {$absOrig}");
        }

        $hls480Dir = "$baseDir/hls_480p";
        $hls360Dir = "$baseDir/hls_360p";
        $hls240Dir = "$baseDir/hls_240p";
        $thumbDir  = "$baseDir/thumbnails";

        foreach ([$hls480Dir, $hls360Dir, $hls240Dir, $thumbDir] as $d) {
            if (!$disk->exists($d)) $disk->makeDirectory($d);
            $abs = $disk->path($d);
            if (!is_writable($abs)) {
                throw new Exception("Output directory not writable: {$abs}");
            }
        }

        $abs480Dir = $disk->path($hls480Dir);
        $abs360Dir = $disk->path($hls360Dir);
        $abs240Dir = $disk->path($hls240Dir);
        $thumbPng  = $disk->path($thumbDir . '/thumb.png');

        // Shared params (CFR 24 -> GOP 96 for 4s segments)
        $fps = 24; $seg = 4; $gop = $fps * $seg; // 96

        // ---- 480p (848x480) Baseline@3.1 -----------------------------------
        $video->update(['progress' => 10]);
        try {
            $this->run([
                $this->ffmpeg,'-y','-v','error',
                '-i', $absOrig,
                '-vsync','cfr','-r',(string)$fps,
                '-vf','scale=848:480',
                '-pix_fmt','yuv420p',
                '-c:v','libx264','-profile:v','baseline','-level','3.1',
                '-x264-params','bframes=0:ref=2:scenecut=0:force-cfr=1',
                '-preset','veryfast',
                '-g',(string)$gop,'-keyint_min',(string)$gop,'-sc_threshold','0',
                '-b:v','900k','-maxrate','950k','-bufsize','1800k',
                '-c:a','aac','-ar','44100','-b:a','128k',
                '-hls_time',(string)$seg,
                '-hls_segment_type','mpegts',
                '-hls_flags','independent_segments',
                '-hls_segment_filename', $abs480Dir.'/seg_%03d.ts',
                '-hls_playlist_type','vod',
                $abs480Dir.'/index.m3u8'
            ], 3600);
        } catch (Exception $e) {
            $this->failNow("480p encode failed", $e);
            return;
        }

        // ---- 360p (640x360) Baseline@3.0 -----------------------------------
        $video->update(['progress' => 45]);
        try {
            $this->run([
                $this->ffmpeg,'-y','-v','error',
                '-i', $absOrig,
                '-vsync','cfr','-r',(string)$fps,
                '-vf','scale=640:360',
                '-pix_fmt','yuv420p',
                '-c:v','libx264','-profile:v','baseline','-level','3.0',
                '-x264-params','bframes=0:ref=2:scenecut=0:force-cfr=1',
                '-preset','veryfast',
                '-g',(string)$gop,'-keyint_min',(string)$gop,'-sc_threshold','0',
                '-b:v','550k','-maxrate','600k','-bufsize','1200k',
                '-c:a','aac','-ar','44100','-b:a','96k',
                '-hls_time',(string)$seg,
                '-hls_segment_type','mpegts',
                '-hls_flags','independent_segments',
                '-hls_segment_filename', $abs360Dir.'/seg_%03d.ts',
                '-hls_playlist_type','vod',
                $abs360Dir.'/index.m3u8'
            ], 3600);
        } catch (Exception $e) {
            $this->failNow("360p encode failed", $e);
            return;
        }

        // ---- 240p (426x240) Baseline@3.0 (extra safe for very low devices) --
        $video->update(['progress' => 60]);
        try {
            $this->run([
                $this->ffmpeg,'-y','-v','error',
                '-i', $absOrig,
                '-vsync','cfr','-r',(string)$fps,
                '-vf','scale=426:240',
                '-pix_fmt','yuv420p',
                '-c:v','libx264','-profile:v','baseline','-level','3.0',
                '-x264-params','bframes=0:ref=2:scenecut=0:force-cfr=1',
                '-preset','veryfast',
                '-g',(string)$gop,'-keyint_min',(string)$gop,'-sc_threshold','0',
                '-b:v','350k','-maxrate','380k','-bufsize','700k',
                '-c:a','aac','-ar','44100','-b:a','64k',
                '-hls_time',(string)$seg,
                '-hls_segment_type','mpegts',
                '-hls_flags','independent_segments',
                '-hls_segment_filename', $abs240Dir.'/seg_%03d.ts',
                '-hls_playlist_type','vod',
                $abs240Dir.'/index.m3u8'
            ], 3600);
        } catch (Exception $e) {
            $this->failNow("240p encode failed", $e);
            return;
        }

     // --- Thumbnail (JPEG) ---
$video->update(['progress' => 70]);

try {
    $thumbRel = "$thumbDir/thumb.jpg";
    $absThumb = $disk->path($thumbRel);

    $this->run([
        $this->ffmpeg,'-y','-v','error',
        '-ss','00:00:05','-i',$absOrig,
        '-frames:v','1',
        // Force full-range JPEG-friendly pixel format:
        '-vf','scale=640:-1,format=yuvj420p',
        '-q:v','2',                 // good quality (2..31; lower = better)
        $absThumb
    ], 300);

    $savedThumbRel = $thumbRel;
} catch (Exception $e) {
    Log::warning("Thumbnail extract failed: {$e->getMessage()}");
    $savedThumbRel = null;
}

        // ---- Master playlist ------------------------------------------------
        $video->update(['progress' => 85]);
        $masterRel = "$baseDir/master.m3u8";
        $masterAbs = $disk->path($masterRel);

        // CODECS use common strings for H.264 Baseline levels
        $master = <<<M3U8
#EXTM3U
#EXT-X-VERSION:3
#EXT-X-INDEPENDENT-SEGMENTS
#EXT-X-STREAM-INF:BANDWIDTH=1100000,AVERAGE-BANDWIDTH=950000,RESOLUTION=848x480,CODECS="avc1.42E01F,mp4a.40.2",FRAME-RATE=24.0
hls_480p/index.m3u8
#EXT-X-STREAM-INF:BANDWIDTH=700000,AVERAGE-BANDWIDTH=600000,RESOLUTION=640x360,CODECS="avc1.42E01E,mp4a.40.2",FRAME-RATE=24.0
hls_360p/index.m3u8
#EXT-X-STREAM-INF:BANDWIDTH=450000,AVERAGE-BANDWIDTH=380000,RESOLUTION=426x240,CODECS="avc1.42E01E,mp4a.40.2",FRAME-RATE=24.0
hls_240p/index.m3u8
M3U8;
        file_put_contents($masterAbs, $master);

        // ---- Save paths -----------------------------------------------------
        $video->update([
            'hls_master_path' => $masterRel,
            'hls_480p_path'   => "$hls480Dir/index.m3u8",
            'hls_360p_path'   => "$hls360Dir/index.m3u8",
            'hls_240p_path'   => "$hls240Dir/index.m3u8",
            'thumbnail_path'  => $thumbRel,
            'status'          => 'ready',
            'progress'        => 100,
            'error_message'   => null,
        ]);
    }

    private function run(array $cmd, int $timeout): void
    {
        $ffLog = storage_path('logs/ffmpeg_'.date('Ymd_His').'_'.uniqid().'.log');
        $proc = new Process($cmd, null, null, null, $timeout);
        $proc->run();

        $stderr = $proc->getErrorOutput();
        $stdout = $proc->getOutput();

        if ($stdout) file_put_contents($ffLog, $stdout.PHP_EOL, FILE_APPEND);
        if ($stderr) file_put_contents($ffLog, $stderr.PHP_EOL, FILE_APPEND);

        if (!$proc->isSuccessful()) {
            $msg = trim($stderr) ?: 'FFmpeg process failed without stderr.';
            $this->lastError = $msg;
            Log::error("FFmpeg failed. CMD: ".implode(' ', $cmd)."\n".$msg."\nLog: ".$ffLog);
            throw new Exception($msg);
        } else {
            Log::info("FFmpeg OK. Log: ".$ffLog);
        }
    }

    private function checkFfmpeg(): bool
    {
        try {
            $p = new Process([$this->ffmpeg,'-version']);
            $p->run();
            if (!$p->isSuccessful()) {
                Log::error("{$this->ffmpeg} -version failed: ".$p->getErrorOutput());
                return false;
            }
            Log::info("FFmpeg detected: ".strtok($p->getOutput(), "\n"));
            return true;
        } catch (\Throwable $e) {
            Log::error("FFmpeg check error: ".$e->getMessage());
            return false;
        }
    }

    private function failNow(string $stage, Exception $e): void
    {
        Log::error("$stage: ".$e->getMessage());
        $this->video->update([
            'status'        => 'failed',
            'progress'      => 0,
            'error_message' => $this->lastError ?: $e->getMessage(),
        ]);
    }

    public function failed(Exception $e): void
    {
        Log::error("ConvertVideo job failed: ".$e->getMessage());
        $this->video->update([
            'status'        => 'failed',
            'progress'      => 0,
            'error_message' => $this->lastError ?: $e->getMessage(),
        ]);
    }
}
