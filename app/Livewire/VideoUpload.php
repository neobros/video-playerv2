<?php

namespace App\Livewire;

use App\Jobs\ConvertVideo;
use App\Models\Video;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;

class VideoUpload extends Component
{
    use WithFileUploads, WithPagination;

    protected string $paginationTheme = 'tailwind';

    #[Validate('required|string|min:2')]
    public string $title = '';

    #[Validate('required|file|mimetypes:video/mp4,video/quicktime,video/x-matroska,video/webm|max:3007200')] // ~300MB
    public $file;
    public $video_type;
    public ?Video $created = null;

    public $examYears = [];        // ["Advanced Level 2025", ...]
    public $classList = [];        // raw list from API
    public $filteredClasses = [];  // classes filtered by selected year

    public $selectedYear = '';     // selected exam year
    public $selectedClassId = '';  // selected class id (from filtered list)

    public $backendSearch = '';

    public $backendYearSearch = '';
    public $backendClassSearch = '';

    public function submit()
    {
        $this->validate();

        $video = Video::create([
            'title' => $this->title,
            'year' =>  $this->selectedYear,
            'class_id' => $this->selectedClassId,
            'original_path' => '',
            'status' => 'pending',
            'progress' => 0,
            
        ]);

        $dir = "videos/{$video->year}/{$video->id}/source";
        Storage::disk('public')->makeDirectory($dir);

        $ext = $this->file->getClientOriginalExtension();
        $stored = $this->file->storeAs($dir, "original.$ext", 'public');

        $video->update(['original_path' => $stored]);

        dispatch(new ConvertVideo($video));

        $this->created = $video->fresh();
        $this->reset(['title','file']);

        session()->flash('ok', 'Uploaded! Conversion started.');
        // Jump to page 1 so the new row appears at top
        $this->resetPage();
    }

    public function render()
    {
        $videos = Video::query()
            ->when($this->selectedClassId, fn ($q) => $q->where('class_id', (string)$this->selectedClassId))
            ->when($this->backendSearch, function ($q) {
                $s = '%'.$this->backendSearch.'%';
                $q->where(fn ($qq) => $qq->where('title','like',$s)
                                        ->orWhere('status','like',$s)
                                        ->orWhere('id','like',$s));
            })
            ->when($this->backendYearSearch, fn ($q,$y) => $q->where('year','like','%'.$y.'%'))
            ->when($this->backendClassSearch, fn ($q,$c) => $q->whereHas('classRelation', function($qq) use($c) {
                $qq->where('name','like','%'.$c.'%');
            }))
            ->latest()
            ->paginate(10);

        return view('livewire.video-upload', compact('videos'));
    }

    public function mount()
    {
        try {
            $res = Http::timeout(8)->get('https://admin.eoe.lk/api/get_common_data_player');

            if (!$res->ok()) {
                Log::warning('common_data_player not OK', ['status' => $res->status()]);
                return;
            }

            $payload = $res->json();

            // defensive checks against structure
            $data = $payload['data'] ?? null;
            if (!$data) {
                Log::warning('common_data_player missing data key', ['payload' => $payload]);
                return;
            }

            $this->examYears = $data['examYearList'] ?? [];
            $this->classList = $data['classList'] ?? [];

            // preselect first year if none chosen
            if (empty($this->selectedYear) && !empty($this->examYears)) {
                $this->selectedYear = $this->examYears[0];
            }

            $this->applyYearFilter();
        } catch (\Throwable $e) {
            Log::warning('common_data_player error: '.$e->getMessage());
            // leave arrays empty so the UI can show a helpful message
        }
    }

    public function updatedSelectedYear()
    {
        $this->applyYearFilter();
        $this->selectedClassId = '';
        $this->resetPage();
    }

    protected function applyYearFilter(): void
    {
        $year = $this->selectedYear;

        // If nothing selected yet, keep filtered empty to make the UI prompt user
        if (!$year) {
            $this->filteredClasses = [];
            return;
        }

        // Filter by academic_year EXACT match
        $this->filteredClasses = collect($this->classList)
            ->filter(fn ($c) => ($c['academic_year'] ?? '') === $year)
            ->sortBy('name')
            ->values()
            ->all();
    }

   public function retryEncode( $id): void
    {
        $video = Video::findOrFail($id);

        // Already running? do nothing.
        if ($video->status === 'processing') {
            session()->flash('ok', "Video #{$video->id} is already processing.");
            return;
        }

        $disk = Storage::disk('public');

        // Must have an original source to retry
        if (!$video->original_path || !$disk->exists($video->original_path)) {
            $video->update([
                'status'        => 'failed',
                'progress'      => 0,
                'error_message' => 'Original file missing — cannot retry.',
            ]);
            session()->flash('ok', "Original missing for #{$video->id}. Retry aborted.");
            return;
        }

        // Paths to clear (keep /source)
        $baseDir   = "videos/{$video->year}/{$video->id}";
        $hls480Dir = "$baseDir/hls_480p";
        $hls360Dir = "$baseDir/hls_360p";
        $hls240Dir = "$baseDir/hls_240p";
        $thumbDir  = "$baseDir/thumbnails";
        $masterRel = "$baseDir/master.m3u8";

        // Remove previous generated assets (safe if absent)
        $disk->deleteDirectory($hls480Dir);
        $disk->deleteDirectory($hls360Dir);
        $disk->deleteDirectory($hls240Dir);
        $disk->deleteDirectory($thumbDir);
        if ($disk->exists($masterRel)) {
            $disk->delete($masterRel);
        }

        // Reset DB fields for a clean retry
        $video->update([
            'hls_master_path' => null,
            'hls_480p_path'   => null,
            'hls_360p_path'   => null,
            'hls_240p_path'   => null,
            'thumbnail_path'  => null,
            'status'          => 'pending',   // job will set 'processing'
            'progress'        => 0,
            'error_message'   => null,
        ]);

        // Queue the job with fresh model instance
        dispatch(new ConvertVideo($video->fresh()));

        session()->flash('ok', "Re-encode queued for #{$video->id}.");
        // optionally force table refresh to reflect 'pending'
        $this->resetPage();
    }

    /**
     * Permanently delete a video:
     * - Deletes /videos/{id} folder (source + HLS + thumbs + master)
     * - Deletes DB row
     * - Clears just-uploaded card if needed
     */
    public function deleteVideo($id): void
    {
        $video   = Video::findOrFail($id);
        $disk    = Storage::disk('public');
        $baseRel = "videos/{$video->year}/{$video->id}";
        $absBase = $disk->path($baseRel);

        // Log who we are & what we’re deleting
        $who = function_exists('posix_geteuid')
            ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown')
            : get_current_user();
        Log::info("deleteVideo: user={$who}, baseRel={$baseRel}, absBase={$absBase}");

        // If nothing to delete, just remove the DB row
        if (!is_dir($absBase)) {
            $video->delete();
            if ($this->created && (string)$this->created->id === (string)$id) $this->created = null;
            session()->flash('ok', "Video #{$id} deleted (no files found, record removed).");
            $this->resetPage();
            return;
        }

        // 1) Try Flysystem first
        $ok = $disk->deleteDirectory($baseRel);

        // 2) If still present, move to .trash/{year}/... (ensure year dir exists)
        if (!$ok && is_dir($absBase)) {
            $trashBaseRel = "videos/.trash";
            $trashYearRel = "videos/.trash/{$video->year}";

            // Use disk to create dirs with correct perms/owner
            if (!$disk->exists($trashBaseRel)) {
                $disk->makeDirectory($trashBaseRel);
            }
            if (!$disk->exists($trashYearRel)) {
                $disk->makeDirectory($trashYearRel);
            }

            $trashTargetRel = "{$trashYearRel}/{$video->id}__" . uniqid();
            $trashTargetAbs = $disk->path($trashTargetRel);

            // Safety: restrict to allowed zone
            $allowedBase = realpath(storage_path('app/public/videos'));
            $absReal     = realpath($absBase) ?: $absBase;

            if ($allowedBase && \Illuminate\Support\Str::startsWith($absReal, $allowedBase)) {
                if (@rename($absBase, $trashTargetAbs)) {
                    Log::warning("Moved {$absBase} to trash: {$trashTargetAbs}");
                    // Try to purge the trash copy (best effort)
                    $this->purgePathBestEffort($trashTargetAbs);
                } else {
                    Log::error("Failed to rename {$absBase} to {$trashTargetAbs} (likely perms).");
                    // Purge in place
                    $this->purgePathBestEffort($absBase);
                }
            } else {
                Log::error("Refusing delete: {$absBase} not under {$allowedBase}");
            }
        }

        // 3) Remove DB row
        $video->delete();

        if ($this->created && (string)$this->created->id === (string)$id) {
            $this->created = null;
        }

        $stillThere = is_dir($absBase);
        session()->flash(
            'ok',
            $stillThere
                ? "Video #{$id} record deleted. Files queued for purge — check logs to fix permissions."
                : "Video #{$id} deleted (files + record)."
        );
        $this->resetPage();
    }

    /**
     * Best-effort purge:
     * - chmod deep (attempt) then unlink/rmdir child-first
     * - logs failures with reasons
     */
    private function purgePathBestEffort(string $absPath): void
    {
        $allowedBase = realpath(storage_path('app/public/videos'));
        $targetReal  = realpath($absPath) ?: $absPath;

        if (!$allowedBase || !\Illuminate\Support\Str::startsWith($targetReal, $allowedBase)) {
            Log::error("purgePathBestEffort refused: {$targetReal} outside {$allowedBase}");
            return;
        }

        // Attempt to make top-level writable
        @chmod($absPath, 0777);

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $fs) {
            $p = $fs->getPathname();
            @chmod($p, 0777);
            if ($fs->isDir()) {
                if (!@rmdir($p)) {
                    $err = error_get_last()['message'] ?? 'rmdir failed';
                    Log::error("rmdir fail: {$p} :: {$err}");
                }
            } else {
                if (!@unlink($p)) {
                    $err = error_get_last()['message'] ?? 'unlink failed';
                    Log::error("unlink fail: {$p} :: {$err}");
                }
            }
        }

        if (!@rmdir($absPath)) {
            $err = error_get_last()['message'] ?? 'rmdir base failed';
            Log::error("rmdir base fail: {$absPath} :: {$err}");
        } else {
            Log::info("Purged: {$absPath}");
        }
    }


}
