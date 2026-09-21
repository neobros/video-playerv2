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
use Illuminate\Support\Facades\Http;

class VideoUpload extends Component
{
    use WithFileUploads, WithPagination;

    protected string $paginationTheme = 'tailwind';

    #[Validate('required|string|min:2')]
    public string $title = '';

    #[Validate('required|file|mimetypes:video/mp4,video/quicktime,video/x-matroska,video/webm|max:3007200')] // ~3GB? (check config)
    public $file;

    public $video_type;
    public ?Video $created = null;

    public $examYears = [];
    public $classList = [];
    public $filteredClasses = [];

    public $selectedYear = '';
    public $selectedClassId = '';

    // ✅ SERVER SIDE SEARCH (works across all pages)
    public $backendSearch = '';

    public function mount()
    {
        try {
            $res = Http::timeout(8)->get('https://admin.eoe.lk/api/get_common_data_player');

            if (!$res->ok()) {
                Log::warning('common_data_player not OK', ['status' => $res->status()]);
                return;
            }

            $payload = $res->json();
            $data = $payload['data'] ?? null;

            if (!$data) {
                Log::warning('common_data_player missing data key', ['payload' => $payload]);
                return;
            }

            $this->examYears = $data['examYearList'] ?? [];
            $this->classList = $data['classList'] ?? [];

            // ✅ AUTO SELECT FIRST YEAR (fix loading time dropdown)
            // if (empty($this->selectedYear) && !empty($this->examYears)) {
            //     $this->selectedYear = $this->examYears[0];
            // }

            $this->applyYearFilter();

            // ✅ if year selected, auto select first class too (optional, but helps)
            if ($this->selectedYear && empty($this->selectedClassId) && !empty($this->filteredClasses)) {
                $this->selectedClassId = (string) ($this->filteredClasses[0]['id'] ?? '');
            }

        } catch (\Throwable $e) {
            Log::warning('common_data_player error: '.$e->getMessage());
        }
    }

    public function updatedSelectedYear()
    {
        $this->applyYearFilter();
        $this->selectedClassId = '';
        $this->resetPage();

        // optional: auto-pick first class for that year
        if (!empty($this->filteredClasses)) {
            $this->selectedClassId = (string) ($this->filteredClasses[0]['id'] ?? '');
        }
    }

    protected function applyYearFilter(): void
    {
        $year = $this->selectedYear;

        if (!$year) {
            $this->filteredClasses = [];
            return;
        }

        $this->filteredClasses = collect($this->classList)
            ->filter(fn ($c) => ($c['academic_year'] ?? '') === $year)
            ->sortBy('name')
            ->values()
            ->all();
    }

    // ✅ Important: when user types search, reset page to 1 (so it searches all pages)
    public function updatedBackendSearch()
    {
        $this->resetPage();
    }

    public function updatedSelectedClassId()
    {
        $this->resetPage();
    }

    public function submit()
    {
        $this->validate();

        $video = Video::create([
            'title'         => $this->title,
            'year'          => $this->selectedYear,
            'class_id'      => $this->selectedClassId,
            'original_path' => '',
            'status'        => 'pending',
            'progress'      => 0,
        ]);

        $dir = "videos/{$video->year}/{$video->id}/source";
        Storage::disk('public')->makeDirectory($dir);

        $ext = $this->file->getClientOriginalExtension();
        $stored = $this->file->storeAs($dir, "original.$ext", 'public');

        $video->update(['original_path' => $stored]);

        dispatch(new ConvertVideo($video));

        $this->created = $video->fresh();
        $this->reset(['title', 'file']);

        session()->flash('ok', 'Uploaded! Conversion started.');
        $this->resetPage();
    }

    public function render()
    {
        $videos = Video::query()
            // ✅ Filter table by selected year & class (so Recent Videos matches selection)
            ->when($this->selectedYear, fn ($q) => $q->where('year', (string)$this->selectedYear))
            ->when($this->selectedClassId, fn ($q) => $q->where('class_id', (string)$this->selectedClassId))

            // ✅ SERVER SIDE SEARCH (works across ALL pages)
            ->when($this->backendSearch, function ($q) {
                $s = '%'.$this->backendSearch.'%';
                $q->where(function ($qq) use ($s) {
                    $qq->where('title', 'like', $s)
                        ->orWhere('status', 'like', $s)
                        ->orWhere('id', 'like', $s)
                        ->orWhere('year', 'like', $s);
                });
            })
            ->latest()
            ->paginate(10);

        return view('livewire.video-upload', [
            'videos' => $videos,
        ]);
    }

    // -------------------------
    // Your existing retryEncode / deleteVideo / purgePathBestEffort 그대로 유지
    // (아래는 그대로 복붙 가능)
    // -------------------------

    public function retryEncode($id): void
    {
        $video = Video::findOrFail($id);

        if ($video->status === 'processing') {
            session()->flash('ok', "Video #{$video->id} is already processing.");
            return;
        }

        $disk = Storage::disk('public');

        if (!$video->original_path || !$disk->exists($video->original_path)) {
            $video->update([
                'status'        => 'failed',
                'progress'      => 0,
                'error_message' => 'Original file missing — cannot retry.',
            ]);
            session()->flash('ok', "Original missing for #{$video->id}. Retry aborted.");
            return;
        }

        $baseDir   = "videos/{$video->year}/{$video->id}";
        $hls480Dir = "$baseDir/hls_480p";
        $hls360Dir = "$baseDir/hls_360p";
        $hls240Dir = "$baseDir/hls_240p";
        $thumbDir  = "$baseDir/thumbnails";
        $masterRel = "$baseDir/master.m3u8";

        $disk->deleteDirectory($hls480Dir);
        $disk->deleteDirectory($hls360Dir);
        $disk->deleteDirectory($hls240Dir);
        $disk->deleteDirectory($thumbDir);
        if ($disk->exists($masterRel)) {
            $disk->delete($masterRel);
        }

        $video->update([
            'hls_master_path' => null,
            'hls_480p_path'   => null,
            'hls_360p_path'   => null,
            'hls_240p_path'   => null,
            'thumbnail_path'  => null,
            'status'          => 'pending',
            'progress'        => 0,
            'error_message'   => null,
        ]);

        dispatch(new ConvertVideo($video->fresh()));

        session()->flash('ok', "Re-encode queued for #{$video->id}.");
        $this->resetPage();
    }

    public function deleteVideo($id): void
    {
        $video   = Video::findOrFail($id);
        $disk    = Storage::disk('public');
        $baseRel = "videos/{$video->year}/{$video->id}";
        $absBase = $disk->path($baseRel);

        $who = function_exists('posix_geteuid')
            ? (posix_getpwuid(posix_geteuid())['name'] ?? 'unknown')
            : get_current_user();

        Log::info("deleteVideo: user={$who}, baseRel={$baseRel}, absBase={$absBase}");

        if (!is_dir($absBase)) {
            $video->delete();
            if ($this->created && (string)$this->created->id === (string)$id) $this->created = null;
            session()->flash('ok', "Video #{$id} deleted (no files found, record removed).");
            $this->resetPage();
            return;
        }

        $ok = $disk->deleteDirectory($baseRel);

        if (!$ok && is_dir($absBase)) {
            $trashBaseRel = "videos/.trash";
            $trashYearRel = "videos/.trash/{$video->year}";

            if (!$disk->exists($trashBaseRel)) $disk->makeDirectory($trashBaseRel);
            if (!$disk->exists($trashYearRel)) $disk->makeDirectory($trashYearRel);

            $trashTargetRel = "{$trashYearRel}/{$video->id}__" . uniqid();
            $trashTargetAbs = $disk->path($trashTargetRel);

            $allowedBase = realpath(storage_path('app/public/videos'));
            $absReal     = realpath($absBase) ?: $absBase;

            if ($allowedBase && \Illuminate\Support\Str::startsWith($absReal, $allowedBase)) {
                if (@rename($absBase, $trashTargetAbs)) {
                    Log::warning("Moved {$absBase} to trash: {$trashTargetAbs}");
                    $this->purgePathBestEffort($trashTargetAbs);
                } else {
                    Log::error("Failed to rename {$absBase} to {$trashTargetAbs} (likely perms).");
                    $this->purgePathBestEffort($absBase);
                }
            } else {
                Log::error("Refusing delete: {$absBase} not under {$allowedBase}");
            }
        }

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

    private function purgePathBestEffort(string $absPath): void
    {
        $allowedBase = realpath(storage_path('app/public/videos'));
        $targetReal  = realpath($absPath) ?: $absPath;

        if (!$allowedBase || !\Illuminate\Support\Str::startsWith($targetReal, $allowedBase)) {
            Log::error("purgePathBestEffort refused: {$targetReal} outside {$allowedBase}");
            return;
        }

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
