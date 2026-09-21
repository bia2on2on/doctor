<?php
/**
 * TEST FIXTURE theme Alpha — index (proof infrastructure only).
 */
get_header(); ?>
<main class="proof-alpha-main">
<?php
if (have_posts()) :
    while (have_posts()) :
        the_post();
        the_title('<h1>', '</h1>');
        the_content();
    endwhile;
else :
    echo '<p>Alpha: no posts</p>';
endif;
?>
</main>
<?php get_footer();
