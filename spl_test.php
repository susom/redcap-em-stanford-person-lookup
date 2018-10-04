<?php
namespace Stanford\SPL;
/** @var \Stanford\SPL\SPL $module */

// A test page for running SPL Lookups

use HtmlPage;



$uid = empty($_POST['uid']) ? "" : $_POST['uid'];

$result = null;
if (!empty($uid)) {
    // Do the lookup
    $result = $module->personLookup($_POST['uid']);
}


$HtmlPage = new HtmlPage();
$HtmlPage->PrintHeaderExt();


// Show the results

?>
    <div class="card card-primary">
        <div class="card-header">
            <?php echo $module->getModuleName() ?>
        </div>
        <div class="card-body">
            <?php
                if ($result === null) {
                    echo "Please enter a sunet id to test the SPL lookup module";
                } elseif ($result === false) {
                    echo "Lookup for $uid did not return any results.";
                } else {
                    echo "<h4>Results for <u>$uid</u></h4><pre>" . print_r($result,true) . "</pre>";
                    $uid = "";
                }
            ?>
        </div>
        <div class="card-footer">
            <form method="POST" action="">

                <div class="input-group">
                    <span class="input-group-addon" id="id_label">Perform Lookup:</span>
                    <input type="text" class="form-control" placeholder="Enter a valid SUNET ID" name="uid" value="<?php echo $uid ?>" aria-describedby="id_label">
                    <span class="input-group-btn">
                        <button type="submit" class="btn btn-primary">Lookup</button>
                    </span>
                </div>
            </form>
        </div>
    </div>
    <script>
        $('input[name="uid"]').focus();
    </script>
