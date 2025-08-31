<div class="max-w-5xl mx-auto space-y-8" x-data="{ search: '' }">

<div class="max-w-5xl mx-auto space-y-8"
     x-data="{
        search: '',
        // tie into Livewire 'title' so we can disable the button until it's filled
        title: @entangle('title'),
        hasFile: false,
        fileName: '',
        fileSize: 0,
        upPct: 0
     }"
     x-on:livewire-upload-start="upPct = 1"
     x-on:livewire-upload-progress="upPct = $event.detail.progress"
     x-on:livewire-upload-error="upPct = 0"
     x-on:livewire-upload-finish="upPct = 100"
>

    {{-- Upload Card --}}
    <form wire:submit.prevent="submit" class="space-y-4 p-6 border rounded-2xl bg-white shadow-sm">
        <h2 class="text-lg font-semibold">Upload a Video</h2>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium mb-1">Title</label>
                <input type="text"
                       wire:model.defer="title"
                       class="w-full border rounded px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                @error('title') <div class="text-red-600 text-sm mt-1">{{ $message }}</div> @enderror
            </div>

            <div class="sm:col-span-2">
                <label class="block text-sm font-medium mb-1">File (mp4/mov/mkv/webm)</label>

                {{-- Hidden native input + pretty select button --}}
                <input type="file"
                       x-ref="pick"
                       class="hidden"
                       accept="video/*"
                       wire:model="file"
                       @change="
                         hasFile = $refs.pick.files.length > 0;
                         if (hasFile) {
                           const f = $refs.pick.files[0];
                           fileName = f?.name || '';
                           fileSize = f?.size || 0;
                         } else {
                           fileName = ''; fileSize = 0;
                         }
                       ">

                <div class="flex flex-wrap items-center gap-3">
                    <button type="button"
                            class="px-3 py-2 rounded-lg border text-gray-700 hover:bg-gray-50"
                            @click="$refs.pick.click()">
                        Select File
                    </button>

                    <div class="text-sm text-gray-700" x-show="hasFile" x-cloak>
                        <span class="font-medium" x-text="fileName"></span>
                        <span class="text-gray-500">·</span>
                        <span class="text-gray-500" x-text="
                          fileSize >= 0
                            ? (fileSize < 1024 ? (fileSize + ' B')
                              : fileSize < 1048576 ? (Math.round(fileSize/1024) + ' KB')
                              : ( (fileSize/1048576).toFixed(1) + ' MB'))
                            : '—'
                        "></span>
                    </div>

                    <button type="button"
                            class="px-3 py-2 rounded-lg border text-gray-700 hover:bg-gray-50"
                            x-show="hasFile"
                            @click="
                              $refs.pick.value = null;
                              hasFile = false; fileName=''; fileSize=0; upPct=0;
                            "
                            x-cloak>
                        Clear
                    </button>
                </div>

                @error('file') <div class="text-red-600 text-sm mt-2">{{ $message }}</div> @enderror

                {{-- Show Livewire temp-upload progress as 'Preparing…' so it's clear nothing is 'submitted' yet --}}
                <div x-show="upPct > 0 && upPct < 100" class="mt-3" x-cloak>
                    <div class="text-sm text-gray-600 mb-1">Preparing file… (<span x-text="upPct"></span>%)</div>
                    <div class="w-full bg-gray-200 rounded h-2 overflow-hidden">
                        <div class="h-2 bg-indigo-600 transition-all" :style="`width: ${upPct}%`"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit"
                    class="px-4 py-2 rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 transition disabled:opacity-50 disabled:cursor-not-allowed"
                    :disabled="!title || !hasFile"
                    wire:loading.attr="disabled"
                    wire:target="submit">
                Upload
            </button>

            @if (session('ok'))
                <div class="text-green-700 text-sm">{{ session('ok') }}</div>
            @endif
        </div>
    </form>

    {{-- Just-uploaded summary --}}
    @if($created)
        <div class="p-5 border rounded-2xl bg-white shadow-sm">
            <div class="flex items-center justify-between">
                <div class="font-semibold flex items-center gap-2">
                    <span class="inline-block w-2.5 h-2.5 rounded-full
                        @if($created->status === 'ready') bg-green-500
                        @elseif($created->status === 'processing') bg-yellow-500
                        @elseif($created->status === 'failed') bg-red-500
                        @else bg-gray-400 @endif"></span>
                    {{ $created->title }}
                </div>
                <div class="text-xs text-gray-500">ID: {{ $created->id }}</div>
            </div>

            <div class="mt-2 text-sm">
                Status:
                <span class="font-mono px-2 py-0.5 rounded-full
                    @if($created->status === 'ready') bg-green-100 text-green-700
                    @elseif($created->status === 'processing') bg-yellow-100 text-yellow-700
                    @elseif($created->status === 'failed') bg-red-100 text-red-700
                    @else bg-gray-100 text-gray-700 @endif">
                    {{ ucfirst($created->status) }}
                </span>
            </div>

            <div class="w-full bg-gray-200 rounded h-2 mt-3 overflow-hidden relative">
                <div class="bg-indigo-600 h-2 transition-all duration-500" style="width: {{ $created->progress }}%"></div>
                <div class="absolute inset-0 flex items-center justify-center text-[11px] font-medium text-gray-700">
                    {{ $created->progress }}%
                </div>
            </div>

            <div class="mt-3 flex flex-wrap items-center gap-3 text-sm">
                @if($created->thumbnail_url)
                    <img src="{{ $created->thumbnail_url }}" alt="thumb" class="w-24 h-16 object-cover rounded border">
                @endif

                @if($created->master_url)
                    <a class="px-3 py-1.5 rounded border text-gray-700 hover:bg-gray-50" href="{{ $created->master_url }}" target="_blank">
                        Open master.m3u8
                    </a>
                    <span x-data="{copied:false}">
                        <button type="button"
                                class="px-3 py-1.5 rounded border text-gray-700 hover:bg-gray-50"
                                @click="navigator.clipboard.writeText('{{ $created->master_url }}'); copied=true; setTimeout(()=>copied=false,1200)">
                            Copy Master URL
                        </button>
                        <span class="text-xs text-green-600" x-show="copied" x-cloak>Copied!</span>
                    </span>
                @endif

                <a href="{{ url('/view2?id='.$created->id) }}" target="_blank"
                   class="px-3 py-1.5 rounded bg-indigo-600 text-white hover:bg-indigo-700">
                    Open Player
                </a>
                <span x-data="{copied:false}">
                    <button type="button"
                            class="px-3 py-1.5 rounded border text-gray-700 hover:bg-gray-50"
                            @click="navigator.clipboard.writeText('{{ url('/view2?id='.$created->id) }}'); copied=true; setTimeout(()=>copied=false,1200)">
                        Copy Player URL
                    </button>
                    <span class="text-xs text-green-600" x-show="copied" x-cloak>Copied!</span>
                </span>
            </div>
        </div>
    @endif

    {{-- sticky header style --}}
    <style>.table-sticky thead th { position: sticky; top: 0; z-index: 1; }</style>

    {{-- Recent Videos Table (auto refresh) --}}
    {{-- … keep your existing table block exactly as you had it … --}}
    {{-- (no change needed below this point) --}}
</div>


    {{-- Small styles for sticky header --}}
    <style>
        .table-sticky thead th { position: sticky; top: 0; z-index: 1; }
    </style>

    {{-- Recent Videos Table (auto refresh) --}}
    <div class="p-0 border rounded-2xl bg-white shadow-sm overflow-hidden" wire:poll.1500ms>
        <div class="px-5 py-4 border-b flex items-center justify-between gap-3 flex-wrap">
            <div class="flex items-center gap-2">
                <h3 class="text-base font-semibold">Recent Videos</h3>
                <span class="text-xs text-gray-500">Auto-refreshing</span>
            </div>
            {{-- client-side search (no backend change) --}}
            <div class="flex items-center gap-2">
                <input type="text" placeholder="Search title / id / status"
                       x-model="search"
                       class="w-56 border rounded px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                <button type="button" wire:click="$refresh"
                        class="px-3 py-1.5 rounded border text-gray-700 hover:bg-gray-50 text-sm">
                    Refresh
                </button>
            </div>
        </div>

        <div class="overflow-x-auto max-h-[65vh]">
            <table class="min-w-full text-sm table-sticky">
                <thead class="bg-gray-50 text-gray-700">
                <tr>
                    <th class="text-left px-4 py-3">ID</th>
                    <th class="text-left px-4 py-3">Title</th>
                    <th class="text-left px-4 py-3">Preview</th>
                    <th class="text-left px-4 py-3">Status</th>
                    <th class="text-left px-4 py-3 w-64">Progress</th>
                    <th class="text-left px-4 py-3">Created</th>
                    <th class="text-left px-4 py-3">Actions</th>
                </tr>
                </thead>
                <tbody class="divide-y">
                @forelse($videos as $v)
                    <tr class="hover:bg-gray-50"
                        x-bind:data-text="'#{{ $v->id }} {{ Str::of($v->title)->lower() }} {{ strtolower($v->status) }}'"
                        x-show="$el.dataset.text.includes(search.toLowerCase())">
                        <td class="px-4 py-3 font-mono text-xs text-gray-700">#{{ $v->id }}</td>

                        <td class="px-4 py-3">
                            <div class="font-medium text-gray-900">{{ $v->title }}</div>
                            @if($v->hls_master_path)
                                <div class="text-[11px] text-gray-500 break-all">/storage/{{ $v->hls_master_path }}</div>
                            @endif
                        </td>

                        <td class="px-4 py-3">
                            @if($v->thumbnail_url)
                                <img src="{{ $v->thumbnail_url }}" alt="thumb" class="w-16 h-10 object-cover rounded border">
                            @else
                                <div class="w-16 h-10 bg-gray-200 rounded"></div>
                            @endif
                        </td>

                        <td class="px-4 py-3">
                            <span class="px-2 py-0.5 rounded-full text-xs
                                @if($v->status === 'ready') bg-green-100 text-green-700
                                @elseif($v->status === 'processing') bg-yellow-100 text-yellow-700
                                @elseif($v->status === 'failed') bg-red-100 text-red-700
                                @else bg-gray-100 text-gray-700 @endif">
                                {{ ucfirst($v->status) }}
                            </span>
                        </td>

                        <td class="px-4 py-3">
                            <div class="w-64 bg-gray-200 rounded h-2 overflow-hidden relative">
                                <div class="h-2 rounded bg-indigo-600 transition-all duration-500" style="width: {{ $v->progress }}%"></div>
                                <div class="absolute inset-0 flex items-center justify-center text-[11px] font-medium text-gray-700">
                                    {{ $v->progress }}%
                                </div>
                            </div>
                        </td>

                        <td class="px-4 py-3 text-gray-700">
                            <div>{{ $v->created_at->format('Y-m-d H:i') }}</div>
                            <div class="text-[11px] text-gray-500">{{ $v->created_at->diffForHumans() }}</div>
                        </td>

                        <td class="px-4 py-3">
                            <div class="flex flex-wrap gap-2 items-center">
                                @if($v->master_url)
                                    <a href="{{ $v->master_url }}" target="_blank"
                                       class="px-2 py-1 rounded border text-gray-700 hover:bg-gray-50">Master</a>
                                    <span x-data="{copied:false}">
                                        <button type="button"
                                                class="px-2 py-1 rounded border text-gray-700 hover:bg-gray-50"
                                                @click="navigator.clipboard.writeText('{{ $v->master_url }}'); copied=true; setTimeout(()=>copied=false,1200)">
                                            Copy
                                        </button>
                                        <span class="text-[11px] text-green-600" x-show="copied" x-cloak>Copied!</span>
                                    </span>
                                @endif
                                <a href="{{ url('/view2?id='.$v->id) }}" target="_blank"
                                   class="px-2 py-1 rounded bg-indigo-600 text-white hover:bg-indigo-700">Open Player</a>
                                <span x-data="{copied:false}">
                                    <button type="button"
                                            class="px-2 py-1 rounded border text-gray-700 hover:bg-gray-50"
                                            @click="navigator.clipboard.writeText('{{ url('/view2?id='.$v->id) }}'); copied=true; setTimeout(()=>copied=false,1200)">
                                        Copy URL
                                    </button>
                                    <span class="text-[11px] text-green-600" x-show="copied" x-cloak>Copied!</span>
                                </span>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6 text-center text-gray-500">No videos yet.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-5 py-3 border-t">
            {{ $videos->links() }}
        </div>
    </div>
</div>
