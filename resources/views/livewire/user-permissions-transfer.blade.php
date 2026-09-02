<div class="p-3 bg-white rounded-lg border border-gray-200 shadow-sm dark:bg-gray-900 dark:border-gray-800 max-w-xl mx-auto"
     dir="rtl"
     x-data="{
         draggedPermId: null,
         handleDragStart(event, id) {
             this.draggedPermId = id;
             event.dataTransfer.setData('text/plain', id);
         },
         handleDropToAssigned(event) {
             event.preventDefault();
             if (this.draggedPermId) {
                 $wire.grantPermission(parseInt(this.draggedPermId));
                 this.draggedPermId = null;
             }
         },
         handleDropToUnassigned(event) {
             event.preventDefault();
             if (this.draggedPermId) {
                 $wire.revokePermission(parseInt(this.draggedPermId));
                 this.draggedPermId = null;
             }
         }
     }">

    <!-- Header -->
    <div class="flex items-center justify-between pb-2 mb-2 border-b border-gray-100 dark:border-gray-800">
        <h4 class="text-xs font-bold text-gray-900 dark:text-white flex items-center gap-1.5">
            <svg class="w-4 h-4 text-primary-600 dark:text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path>
            </svg>
            الصلاحيات المباشرة (Direct Permissions)
        </h4>
        <span class="text-[10px] text-gray-400 dark:text-gray-500">اختر، نقر مزدوج أو سحب وإفلات</span>
    </div>

    <!-- Ultra-Compact Side-by-Side Container (Max-Width ~500px) -->
    <div class="flex items-center justify-center gap-2">
        
        <!-- Box 1 (Right): Unassigned Permissions (Fixed w-48/w-52 | 190px - 210px) -->
        <div class="w-48 sm:w-52 flex flex-col bg-gray-50 dark:bg-gray-950/50 p-2 rounded-lg border border-gray-200 dark:border-gray-800 shrink-0"
             x-on:dragover.prevent
             x-on:drop="handleDropToUnassigned($event)">
            
            <div class="flex items-center justify-between mb-1.5">
                <span class="text-[11px] font-bold text-gray-800 dark:text-gray-200 truncate">
                    الصلاحيات المتاحة
                </span>
                <span class="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-gray-200 text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                    {{ count($unassignedPermissions) }}
                </span>
            </div>

            <!-- Search Input (h-7 text-xs ~28px) -->
            <div class="mb-1.5">
                <input type="text"
                       wire:model.live.debounce.200ms="searchUnassigned"
                       placeholder="🔍 بحث..."
                       class="w-full h-7 text-xs px-2 py-0.5 rounded border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 shadow-sm focus:ring-1 focus:ring-primary-500 focus:border-primary-500">
            </div>

            <!-- Listbox (h-48 ~190px-200px smooth overflow-y-auto scrollbar) -->
            <div class="h-48 overflow-y-auto space-y-1 pl-1 pr-0.5 text-xs"
                 style="scrollbar-width: thin; scrollbar-color: #cbd5e1 transparent;">
                @forelse($unassignedPermissions as $perm)
                    <div draggable="true"
                         x-on:dragstart="handleDragStart($event, {{ $perm->id }})"
                         wire:dblclick="grantPermission({{ $perm->id }})"
                         title="{{ $perm->label_ar ?: $perm->name }} ({{ $perm->name }})"
                         class="group flex items-center justify-between py-1 px-1.5 bg-white dark:bg-gray-800 rounded border border-gray-200/80 dark:border-gray-700/60 hover:border-primary-500 dark:hover:border-primary-500 transition cursor-grab active:cursor-grabbing select-none">
                        
                        <label class="flex items-center gap-1.5 min-w-0 w-full cursor-pointer">
                            <input type="checkbox"
                                   wire:model.live="selectedUnassigned"
                                   value="{{ $perm->id }}"
                                   class="w-3.5 h-3.5 rounded border-gray-300 text-primary-600 focus:ring-0 dark:bg-gray-700 shrink-0">
                            <span class="text-[11px] font-medium text-gray-800 dark:text-gray-200 truncate group-hover:text-primary-600 dark:group-hover:text-primary-400">
                                {{ $perm->label_ar ?: ($perm->display_name ?: $perm->name) }}
                            </span>
                        </label>
                    </div>
                @empty
                    <div class="flex flex-col items-center justify-center h-36 text-center text-gray-400 dark:text-gray-500">
                        <span class="text-[10px]">لا توجد عناصر</span>
                    </div>
                @endforelse
            </div>
        </div>

        <!-- Middle Transfer Controls (Square Buttons w-7 h-7) -->
        <div class="flex flex-col items-center justify-center gap-1.5 shrink-0 px-1">
            
            <!-- Transfer to Left (Grant) -->
            <button wire:click="grantSelected"
                    type="button"
                    title="إسناد الصلاحيات المحددة (نقل لليسار)"
                    class="w-7 h-7 rounded bg-emerald-600 hover:bg-emerald-700 text-white shadow transition transform active:scale-90 flex items-center justify-center"
                    aria-label="Grant">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                </svg>
            </button>

            <!-- Transfer to Right (Revoke) -->
            <button wire:click="revokeSelected"
                    type="button"
                    title="سحب الصلاحيات المحددة (نقل لليمين)"
                    class="w-7 h-7 rounded bg-rose-600 hover:bg-rose-700 text-white shadow transition transform active:scale-90 flex items-center justify-center"
                    aria-label="Revoke">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M14 5l7 7m0 0l-7 7m7-7H3"></path>
                </svg>
            </button>
        </div>

        <!-- Box 2 (Left): Assigned Permissions (Fixed w-48/w-52 | 190px - 210px) -->
        <div class="w-48 sm:w-52 flex flex-col bg-emerald-50/40 dark:bg-emerald-950/20 p-2 rounded-lg border border-emerald-200 dark:border-emerald-800/40 shadow-inner shrink-0"
             x-on:dragover.prevent
             x-on:drop="handleDropToAssigned($event)">
            
            <div class="flex items-center justify-between mb-1.5">
                <span class="text-[11px] font-bold text-emerald-900 dark:text-emerald-300 truncate">
                    الصلاحيات المسندة
                </span>
                <span class="px-1.5 py-0.5 text-[10px] font-semibold rounded bg-emerald-100 text-emerald-800 dark:bg-emerald-900/80 dark:text-emerald-200">
                    {{ count($assignedPermissions) }}
                </span>
            </div>

            <!-- Search Input (h-7 text-xs ~28px) -->
            <div class="mb-1.5">
                <input type="text"
                       wire:model.live.debounce.200ms="searchAssigned"
                       placeholder="🔍 بحث..."
                       class="w-full h-7 text-xs px-2 py-0.5 rounded border-gray-300 dark:border-gray-700 bg-white dark:bg-gray-800 text-gray-900 dark:text-gray-100 shadow-sm focus:ring-1 focus:ring-emerald-500 focus:border-emerald-500">
            </div>

            <!-- Listbox (h-48 ~190px-200px smooth overflow-y-auto scrollbar) -->
            <div class="h-48 overflow-y-auto space-y-1 pl-1 pr-0.5 text-xs"
                 style="scrollbar-width: thin; scrollbar-color: #a7f3d0 transparent;">
                @forelse($assignedPermissions as $perm)
                    <div draggable="true"
                         x-on:dragstart="handleDragStart($event, {{ $perm->id }})"
                         wire:dblclick="revokePermission({{ $perm->id }})"
                         title="{{ $perm->label_ar ?: $perm->name }} ({{ $perm->name }})"
                         class="group flex items-center justify-between py-1 px-1.5 bg-white dark:bg-gray-800 rounded border border-emerald-200/80 dark:border-emerald-800/40 hover:border-emerald-500 dark:hover:border-emerald-500 transition cursor-grab active:cursor-grabbing select-none">
                        
                        <label class="flex items-center gap-1.5 min-w-0 w-full cursor-pointer">
                            <input type="checkbox"
                                   wire:model.live="selectedAssigned"
                                   value="{{ $perm->id }}"
                                   class="w-3.5 h-3.5 rounded border-gray-300 text-emerald-600 focus:ring-0 dark:bg-gray-700 shrink-0">
                            <span class="text-[11px] font-medium text-gray-800 dark:text-gray-200 truncate group-hover:text-emerald-600 dark:group-hover:text-emerald-400">
                                {{ $perm->label_ar ?: ($perm->display_name ?: $perm->name) }}
                            </span>
                        </label>
                    </div>
                @empty
                    <div class="flex flex-col items-center justify-center h-36 text-center text-gray-400 dark:text-gray-500">
                        <span class="text-[10px]">لا توجد صلاحيات مسندة</span>
                    </div>
                @endforelse
            </div>
        </div>

    </div>
</div>
