<div class="max-w-5xl mx-auto space-y-8"
     x-data="{
        search: '',
        title: @entangle('title'),
        selectedYear: @entangle('selectedYear'),
        selectedClassId: @entangle('selectedClassId'),
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
            {{-- Title --}}
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium mb-1">Title</label>
                <input type="text"
                       wire:model.defer="title"
                       class="w-full border rounded px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                @error('title') <div class="text-red-600 text-sm mt-1">{{ $message }}</div> @enderror
            </div>

        {{-- Exam Year --}}
        <div>
        <label class="block text-sm font-medium mb-1">Exam Year</label>
        <select
            wire:model="selectedYear" {{-- <- NOT defer --}}
            class="w-full border rounded px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
            <option value="">-- Select Year --</option>
            @foreach($examYears as $y)
            <option value="{{ $y }}">{{ $y }}</option>
            @endforeach
        </select>
        @error('selectedYear') <div class="text-red-600 text-sm mt-1">{{ $message }}</div> @enderror

        {{-- tiny debug/help text --}}
        <p class="text-xs text-gray-500 mt-1">
            Years loaded: {{ is_countable($examYears) ? count($examYears) : 0 }}
        </p>
        </div>
        @if(empty($examYears))
        <div class="mt-2 text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded p-2">
            No exam years loaded. Check server logs for "common_data_player" warnings.
        </div>
        @endif
        @if($selectedYear && empty($filteredClasses))
        <div class="mt-2 text-xs text-blue-700 bg-blue-50 border border-blue-200 rounded p-2">
            Year selected: "{{ $selectedYear }}", but 0 classes matched academic_year.
            Confirm academic_year strings match exactly.
        </div>
        @endif

        {{-- Class (depends on year) --}}
        <div>
        <label class="block text-sm font-medium mb-1">Class</label>
        <select
            wire:model="selectedClassId" {{-- <- NOT defer --}}
            class="w-full border rounded px-3 py-2 focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
            @disabled(!$selectedYear)>
            <option value="">-- Select Class --</option>

            @forelse($filteredClasses as $c)
            <option value="{{ $c['id'] }}">{{ $c['name'] }}</option>
            @empty
            {{-- If empty, show a disabled placeholder --}}
            <option value="" disabled>(No classes for "{{ $selectedYear ?: '—' }}")</option>
            @endforelse
        </select>
        @error('selectedClassId') <div class="text-red-600 text-sm mt-1">{{ $message }}</div> @enderror

        {{-- tiny debug/help text --}}
        <p class="text-xs text-gray-500 mt-1">
            Classes for year: {{ $selectedYear ?: '—' }} · {{ is_countable($filteredClasses) ? count($filteredClasses) : 0 }}
        </p>
        </div>


            {{-- (Optional) Video Type select you added earlier --}}
            {{-- ... keep or remove as you wish ... --}}

            {{-- File --}}
            <div class="sm:col-span-2">
                <label class="block text-sm font-medium mb-1">File (mp4/mov/mkv/webm)</label>
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
                              : ((fileSize/1048576).toFixed(1) + ' MB'))
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
                    :disabled="!title || !hasFile || !selectedYear || !selectedClassId"
                    wire:loading.attr="disabled"
                    wire:target="submit">
                Upload
            </button>

            @if (session('ok'))
                <div class="text-green-700 text-sm">{{ session('ok') }}</div>
            @endif
        </div>
    </form>

    {{-- Just-uploaded summary (unchanged) --}}
    @if($created)
        {{-- ... your existing block ... --}}
    @endif

    {{-- Recent Videos Table (auto refresh) --}}
    <div class="p-0 border rounded-2xl bg-white shadow-sm overflow-hidden" wire:poll.1500ms>
        <div class="px-5 py-4 border-b flex items-center justify-between gap-3 flex-wrap">
            <div class="flex items-center gap-2">
                <h3 class="text-base font-semibold">Recent Videos</h3>
                <span class="text-xs text-gray-500">Auto-refreshing</span>
            </div>

            {{-- TOP RIGHT: quick filters --}}
            <div class="flex items-center gap-2 flex-wrap">
                {{-- Echo current filter for clarity --}}
                <div class="text-xs text-gray-500">
                    <span>Year:</span>
                    <span class="font-medium" x-text="selectedYear || '—'"></span>
                    <span class="mx-1">/</span>
                    <span>Class:</span>
                    <span class="font-medium">
                        @php
                            $selected = collect($filteredClasses ?? [])->firstWhere('id', (int)$selectedClassId);
                        @endphp
                        {{ $selected['name'] ?? '—' }}
                    </span>
                </div>

                {{-- Client-side search remains --}}
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
                    <th class="text-left px-4 py-3">Year / Class</th> {{-- NEW --}}
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
                     
                            {{-- show class name under title (optional) --}}
                            @php
                                $cls = collect($filteredClasses ?? [])
                                    ->firstWhere('id', (int)($v->class_id ?? 0));
                            @endphp
                            @if($v->class_id)
                                <div class="text-[11px] text-gray-500">
                                    Class: {{ $cls['name'] ?? ('#'.$v->class_id) }}
                                </div>
                            @endif
                        </td>

                        {{-- NEW: Year / Class cell --}}
                        @php
                            // Find class name from the full list, not just filteredClasses (so old rows still resolve)
                            $clsAll = collect($classList ?? [])->firstWhere('id', (int)($v->class_id ?? 0));
                            $className = $clsAll['name'] ?? null;
                        @endphp
                        <td class="px-4 py-3">
                            <div class="flex flex-wrap items-center gap-2">
                                {{-- Year pill --}}
                                <span class="inline-flex items-center rounded-full bg-gray-100 text-gray-700 px-2 py-0.5 text-xs">
                                    {{ $v->year ?? '—' }}
                                </span>
                                {{-- Class pill --}}
                                <span class="inline-flex items-center rounded-full bg-indigo-50 text-indigo-700 px-2 py-0.5 text-xs">
                                    {{ $className ?? ($v->class_id ? '#'.$v->class_id : '—') }}
                                </span>
                            </div>
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
                            <button
                            type="button"
                            class="px-2 py-1 rounded border text-gray-700 hover:bg-gray-50 text-sm"
                            wire:click="retryEncode('{{ $v->id }}')"
                            wire:loading.attr="disabled"
                            wire:target="retryEncode">
                            Retry Encode
                            </button>

                       
                             -->
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
