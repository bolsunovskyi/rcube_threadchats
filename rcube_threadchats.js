window.rcmail && rcmail.addEventListener('init', function(evt) {
    rcmail.register_command('plugin.rcube_threadchats-save', function() {

        rcmail.gui_objects.threadchatsfrm.submit();


    }, true);
});