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
        $this->ffmpeg = (string) (config('video.ffmpeg') ?? '/usr/bin/ffmpeg');

        $video = $this->video->refresh();
        $video->update([
            'status'        => 'processing',
            'progress'      => 1,
            'error_message' => null,
        ]);

        $disk = Storage::disk('public');

        // ---- Checks ---------------------------------------------------------
        if (!$this->checkFfmpeg()) {
            throw new Exception("ffmpeg not found or not executable at: {$this->ffmpeg}");
        }

        $baseDir = "videos/{$video->year}/{$video->id}";
        $origRel = $video->original_path;
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
        $hls144Dir = "$baseDir/hls_144p";
        $thumbDir  = "$baseDir/thumbnails";

        foreach ([$hls480Dir, $hls360Dir, $hls240Dir, $hls144Dir, $thumbDir] as $d) {
            if (!$disk->exists($d)) $disk->makeDirectory($d);
            $abs = $disk->path($d);
            if (!is_writable($abs)) {
                throw new Exception("Output directory not writable: {$abs}");
            }
        }

        $abs480Dir = $disk->path($hls480Dir);
        $abs360Dir = $disk->path($hls360Dir);
        $abs240Dir = $disk->path($hls240Dir);
        $abs144Dir = $disk->path($hls144Dir);

        // --------------------------------------------------------------------
        // ✅ OPTIMIZED FOR LOW NETWORK USERS (e.g., 3G ~500kbps)
        // Added: 144p ultra-low rung for very weak networks
        // --------------------------------------------------------------------

        $fps = 24;
        $seg = 4;                  // 4s segments (good balance)
        $gop = $fps * $seg;        // segment-aligned GOP
        $forceKeyExpr = "expr:gte(t,n_forced*{$seg})";

        // Shared base arguments
        $baseArgs = [
            $this->ffmpeg, '-y',
            '-hide_banner',
            '-v', 'error',
            '-i', $absOrig,

            // make HLS segments switch-friendly
            '-vsync', 'cfr',
            '-r', (string)$fps,

            // H.264 baseline for widest device support
            '-c:v', 'libx264',
            '-profile:v', 'baseline',

            // keep segments clean + ABR-friendly
            '-g', (string)$gop,
            '-keyint_min', (string)$gop,
            '-sc_threshold', '0',
            '-force_key_frames', $forceKeyExpr,

            // remove b-frames (baseline safe + less decode complexity)
            '-x264-params', 'bframes=0:ref=2:scenecut=0:force-cfr=1',

            // output pixel format for compatibility
            '-pix_fmt', 'yuv420p',

            // HLS options
            '-hls_time', (string)$seg,
            '-hls_segment_type', 'mpegts',
            '-hls_flags', 'independent_segments',
            '-hls_playlist_type', 'vod',

            // if some videos have weird timestamps
            '-fflags', '+genpts',
            '-max_muxing_queue_size', '4096',
        ];

        // ---- 480p -----------------------------------------------------------
        $video->update(['progress' => 10]);
        try {
            $this->run(array_merge($baseArgs, [
                '-vf', 'scale=848:480',

                // CRF + VBV
                '-crf', '23',
                '-maxrate', '800k',
                '-bufsize', '1600k',

                '-preset', 'veryfast',

                // audio
                '-c:a', 'aac',
                '-ar', '44100',
                '-b:a', '96k',

                '-hls_segment_filename', $abs480Dir.'/seg_%05d.ts',
                $abs480Dir.'/index.m3u8',
            ]), 3600);
        } catch (Exception $e) {
            $this->failNow("480p encode failed", $e);
            return;
        }

        // ---- 360p -----------------------------------------------------------
        $video->update(['progress' => 40]);
        try {
            $this->run(array_merge($baseArgs, [
                '-vf', 'scale=640:360',
                '-level', '3.0',

                // CRF + VBV tuned for low bandwidth
                '-crf', '24',
                '-maxrate', '380k',
                '-bufsize', '760k',

                '-preset', 'veryfast',

                // smaller audio
                '-c:a', 'aac',
                '-ar', '32000',
                '-b:a', '48k',

                '-hls_segment_filename', $abs360Dir.'/seg_%05d.ts',
                $abs360Dir.'/index.m3u8',
            ]), 3600);
        } catch (Exception $e) {
            $this->failNow("360p encode failed", $e);
            return;
        }

        // ---- 240p -----------------------------------------------------------
        $video->update(['progress' => 60]);
        try {
            $this->run(array_merge($baseArgs, [
                '-vf', 'scale=426:240',
                '-level', '3.0',

                // lower ladder
                '-crf', '26',
                '-maxrate', '260k',
                '-bufsize', '520k',

                '-preset', 'veryfast',

                // smaller audio
                '-c:a', 'aac',
                '-ar', '32000',
                '-b:a', '40k',

                '-hls_segment_filename', $abs240Dir.'/seg_%05d.ts',
                $abs240Dir.'/index.m3u8',
            ]), 3600);
        } catch (Exception $e) {
            $this->failNow("240p encode failed", $e);
            return;
        }

        // ---- 144p (ultra-safe for very weak networks) -----------------------
        // Target: ~140-180k video + 24k audio ≈ 165-205k total
        $video->update(['progress' => 75]);
        try {
            $this->run(array_merge($baseArgs, [
                // 16:9 approximate width for 144p
                '-vf', 'scale=256:144',
                '-level', '3.0',

                // very low VBV for weak networks
                '-crf', '28',
                '-maxrate', '180k',
                '-bufsize', '360k',

                '-preset', 'veryfast',

                // tiny audio
                '-c:a', 'aac',
                '-ar', '22050',
                '-b:a', '24k',

                '-hls_segment_filename', $abs144Dir.'/seg_%05d.ts',
                $abs144Dir.'/index.m3u8',
            ]), 3600);
        } catch (Exception $e) {
            $this->failNow("144p encode failed", $e);
            return;
        }

        // --- Thumbnail (JPEG) ------------------------------------------------
        $video->update(['progress' => 85]);
        $thumbRel = "$thumbDir/thumb.jpg";
        try {
            $absThumb = $disk->path($thumbRel);

            $this->run([
                $this->ffmpeg, '-y', '-hide_banner', '-v', 'error',
                '-ss', '00:00:05', '-i', $absOrig,
                '-frames:v', '1',
                '-vf', 'scale=640:-1,format=yuvj420p',
                '-q:v', '2',
                $absThumb
            ], 300);
        } catch (Exception $e) {
            Log::warning("Thumbnail extract failed: {$e->getMessage()}");
            $thumbRel = null;
        }

        // ---- Master playlist ------------------------------------------------
        $video->update(['progress' => 92]);
        $masterRel = "$baseDir/master.m3u8";
        $masterAbs = $disk->path($masterRel);

        // ✅ Bandwidth values updated to match ladder (include 144p)
        $master = <<<M3U8
#EXTM3U
#EXT-X-VERSION:3
#EXT-X-INDEPENDENT-SEGMENTS
#EXT-X-STREAM-INF:BANDWIDTH=950000,AVERAGE-BANDWIDTH=850000,RESOLUTION=848x480,CODECS="avc1.42E01F,mp4a.40.2",FRAME-RATE=24.0
hls_480p/index.m3u8
#EXT-X-STREAM-INF:BANDWIDTH=480000,AVERAGE-BANDWIDTH=420000,RESOLUTION=640x360,CODECS="avc1.42E01E,mp4a.40.2",FRAME-RATE=24.0
hls_360p/index.m3u8
#EXT-X-STREAM-INF:BANDWIDTH=330000,AVERAGE-BANDWIDTH=290000,RESOLUTION=426x240,CODECS="avc1.42E01E,mp4a.40.2",FRAME-RATE=24.0
hls_240p/index.m3u8
#EXT-X-STREAM-INF:BANDWIDTH=220000,AVERAGE-BANDWIDTH=190000,RESOLUTION=256x144,CODECS="avc1.42E01E,mp4a.40.2",FRAME-RATE=24.0
hls_144p/index.m3u8
M3U8;

        file_put_contents($masterAbs, $master);

        // ---- Save paths -----------------------------------------------------
        $video->update([
            'hls_master_path' => $masterRel,
            'hls_480p_path'   => "$hls480Dir/index.m3u8",
            'hls_360p_path'   => "$hls360Dir/index.m3u8",
            'hls_240p_path'   => "$hls240Dir/index.m3u8",
            'hls_144p_path'   => "$hls144Dir/index.m3u8",
            'thumbnail_path'  => $thumbRel,
            'status'          => 'ready',
            'progress'        => 100,
            'error_message'   => null,
        ]);
    }

    private function run(array $cmd, int $timeout): void
    {
        $ffLog = storage_path('logs/ffmpeg_' . date('Ymd_His') . '_' . uniqid() . '.log');
        $proc = new Process($cmd, null, null, null, $timeout);
        $proc->run();

        $stderr = $proc->getErrorOutput();
        $stdout = $proc->getOutput();

        if ($stdout) file_put_contents($ffLog, $stdout . PHP_EOL, FILE_APPEND);
        if ($stderr) file_put_contents($ffLog, $stderr . PHP_EOL, FILE_APPEND);

        if (!$proc->isSuccessful()) {
            $msg = trim($stderr) ?: 'FFmpeg process failed without stderr.';
            $this->lastError = $msg;
            Log::error("FFmpeg failed. CMD: " . implode(' ', $cmd) . "\n" . $msg . "\nLog: " . $ffLog);
            throw new Exception($msg);
        } else {
            Log::info("FFmpeg OK. Log: " . $ffLog);
        }
    }

    private function checkFfmpeg(): bool
    {
        try {
            $p = new Process([$this->ffmpeg, '-version']);
            $p->run();
            if (!$p->isSuccessful()) {
                Log::error("{$this->ffmpeg} -version failed: " . $p->getErrorOutput());
                return false;
            }
            Log::info("FFmpeg detected: " . strtok($p->getOutput(), "\n"));
            return true;
        } catch (\Throwable $e) {
            Log::error("FFmpeg check error: " . $e->getMessage());
            return false;
        }
    }

    private function failNow(string $stage, Exception $e): void
    {
        Log::error("$stage: " . $e->getMessage());
        $this->video->update([
            'status'        => 'failed',
            'progress'      => 0,
            'error_message' => $this->lastError ?: $e->getMessage(),
        ]);
    }

    public function failed(Exception $e): void
    {
        Log::error("ConvertVideo job failed: " . $e->getMessage());
        $this->video->update([
            'status'        => 'failed',
            'progress'      => 0,
            'error_message' => $this->lastError ?: $e->getMessage(),
        ]);
    }
}
