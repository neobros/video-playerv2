<?php

namespace App\Livewire;

use App\Jobs\ConvertVideo;
use App\Models\Video;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class VideoUpload extends Component
{
    use WithFileUploads, WithPagination;

    protected string $paginationTheme = 'tailwind';

    #[Validate('required|string|min:2')]
    public string $title = '';

    #[Validate('required|file|mimetypes:video/mp4,video/quicktime,video/x-matroska,video/webm|max:3007200')] // ~300MB
    public $file;

    public ?Video $created = null;

    public function submit()
    {
        $this->validate();

        $video = Video::create([
            'title' => $this->title,
            'original_path' => '',
            'status' => 'pending',
            'progress' => 0,
        ]);

        $dir = "videos/{$video->id}/source";
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
        $videos = Video::latest()->paginate(10);
        return view('livewire.video-upload', compact('videos'));
    }
}
