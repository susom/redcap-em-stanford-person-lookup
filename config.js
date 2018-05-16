var SPL = SPL || {};


SPL.init = function(setting) {

    // Get the width of the config table (based on current browser width)
    var width = $('div.modal-body:visible').width()-(30+16);

    // Make content and insert
    var a = $("<tr>").append(
        $("<td colspan=3>").append(
            $("<div>").addClass('panel panel-primary').css({"max-width": width}).append(
                $("<div>").addClass('panel-body').append(
                    $("<p><b>To access this lookup, please post to the following url:</b></p>")
                ).append(
                    $("<pre>").text(SPL.apiUrl)
                ).append(
                    $("<p><b>with parameters <code>token: XXXX</code> and <code>uid: YYYY</code></b></p>")
                )
            )
        )
    ).insertAfter($('tr[field="ext_desc"]:first'));


    // Make db-warning tr
    var b = $('<tr>')
        .append(
        $('<td colspan=3>').append(
            $('<div>')
            .addClass('alert alert-danger')
            .addClass('db-warning')
            .attr('style','display:none;')
            .html("<p class='text-center'><b>To use the DB cache method you must" +
            " create the following table:</b></p>" +
                "<pre>CREATE TABLE `stanford_person_lookup_cache` (\n" +
                "  `id` varchar(100) NOT NULL,\n" +
                "  `result` text,\n" +
                "  `date_cached` datetime DEFAULT NULL,\n" +
                "  PRIMARY KEY (`id`)\n" +
                ") ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='A cache for the Stanford Person Lookup'</pre>" +
                "Reload this page after creating the table to verify it exists.")
        ).append(
            $('<div>')
                .addClass('alert alert-success')
                .addClass('db-success')
                .attr('style','display:none;')
                .html("<p class='text-center'><b>DB Cache Table Verified</b></p>")
        )
    ).insertAfter('tr[field="cache_method"]');


    // Bind an event hander to display the db-warning tr
    $('input[name="cache_method"]')
        .bind('change',function() { SPL.checkCacheMethod(); })
        .trigger('change');
};


// Update the warnings
SPL.checkCacheMethod = function() {
    var method = $('input[name="cache_method"]:checked').val();
    if (method === "db") {
        if (SPL.dbTableExists === false) {
            $('.db-warning').fadeIn();
            $('.db-success').fadeOut();
        } else {
            $('.db-warning').fadeOut();
            $('.db-success').fadeIn();
        }
    } else {
        $('.db-warning').fadeOut();
        $('.db-success').fadeOut();
    }
};

