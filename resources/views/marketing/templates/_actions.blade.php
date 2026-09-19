<div class="btn-group btn-group-sm">
    <button class="btn btn-outline-primary js-view" data-id="{{ $template->id }}" title="Preview">
        <i class="bi bi-eye"></i>
    </button>

    @can('whatsapp_templates.delete')
        <button class="btn btn-outline-danger js-delete" data-id="{{ $template->id }}" title="Delete from Meta">
            <i class="bi bi-trash"></i>
        </button>
    @endcan
</div>
