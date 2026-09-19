<div class="d-flex gap-1">
    <a href="{{ route('leads.show', $lead) }}" class="btn btn-xs btn-outline-primary" title="View">
        <i class="bi bi-eye"></i>
    </a>
    @can('leads.edit')
    <a href="{{ route('leads.edit', $lead) }}" class="btn btn-xs btn-outline-secondary" title="Edit">
        <i class="bi bi-pencil"></i>
    </a>
    @endcan
    @can('leads.delete')
    <button class="btn btn-xs btn-outline-danger" title="Delete" onclick="deleteLead({{ $lead->id }})">
        <i class="bi bi-trash"></i>
    </button>
    @endcan
</div>
<script>
function deleteLead(id) {
    Swal.fire({ title:'Delete Lead?', text:'This action cannot be undone.', icon:'warning',
        showCancelButton:true, confirmButtonColor:'#dc3545', confirmButtonText:'Delete' })
    .then(r => {
        if (r.isConfirmed) {
            $.ajax({ url: `/leads/${id}`, method: 'DELETE' })
                .done(() => { toastr.success('Lead deleted.'); window.leadsTable?.ajax.reload(); })
                .fail(() => toastr.error('Failed to delete lead.'));
        }
    });
}
</script>
