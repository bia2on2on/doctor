<?php
/**
 * TEST FIXTURE theme Beta — index (proof infrastructure only).
 */
get_header(); ?>
<div class="proof-beta-column">
<main class="proof-beta-main">
<?php
if (have_posts()) :
    while (have_posts()) :
        the_post();
        the_title('<h2>', '</h2>');
        the_content();
    endwhile;
else :
    echo '<p>Beta: nothing here</p>';
endif;
?>
</main>
</div>
<?php get_footer();
