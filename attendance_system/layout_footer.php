
    </div><!-- end main-content -->
</div><!-- end main-wrap -->

<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/toastr.js/latest/toastr.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>

<script>
// Toastr config
toastr.options = {
    positionClass: 'toast-top-right',
    timeOut: 3500,
    progressBar: true,
    closeButton: true,
    newestOnTop: true
};

// Sidebar toggle
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('active');
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('active');
}

// Init DataTables
$(document).ready(function() {
    if ($('.data-table').length) {
        $('.data-table').DataTable({
            responsive: true,
            pageLength: 15,
            language: {
                search: '',
                searchPlaceholder: 'Search...',
                lengthMenu: 'Show _MENU_ entries',
                info: 'Showing _START_ to _END_ of _TOTAL_ entries',
            },
            dom: '<"row align-items-center mb-3"<"col-sm-6"l><"col-sm-6 text-end"f>>rt<"row align-items-center mt-3"<"col-sm-6"i><"col-sm-6"p>>',
        });
    }
});

// AJAX helper
function ajaxPost(url, data, callback) {
    $.ajax({
        url: url, type: 'POST',
        data: data, dataType: 'json',
        success: function(res) { callback(null, res); },
        error: function(xhr) {
            try { callback(JSON.parse(xhr.responseText), null); }
            catch(e) { callback({error: 'Server error'}, null); }
        }
    });
}

// Confirm delete
function confirmDelete(url, msg) {
    if (confirm(msg || 'Are you sure you want to delete this?')) {
        window.location.href = url;
    }
}
</script>

<?php if (isset($extraScripts)) echo $extraScripts; ?>
</body>
</html>
