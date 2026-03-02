<?php

/**
 * Template for the Reader Q&A Hub - Vertical Facets Version
 */

wp_enqueue_style(
  'mp-qa-hub-style',
  plugins_url('../css/mp-qa-hub.css', __FILE__),
  [],
  time()
);

get_header();

// Safety check for logs
// $current_sort = $_GET['sort'] ?? 'newest';
$queried_object = get_queried_object();
$current_tax_id = $queried_object->term_id ?? 0;


$current_sort = $_GET['sort'] ?? 'newest';
$current_cat  = $_GET['category_name'] ?? '';
$current_tag  = $_GET['tag'] ?? '';
$base_url     = home_url('/questions/');
?>

<div class="mp-hub-wrapper">
  <header class="mp-hub-header">
    <h2 class="kt-adv-heading177_7f7566-76 wp-block-kadence-advancedheading has-theme-palette-3-color has-text-color" data-kb-block="kb-adv-heading177_7f7566-76"><mark style="background-color:rgba(0, 0, 0, 0)" class="has-inline-color has-theme-palette-1-color">#asked</mark> &amp; <mark style="background-color:rgba(0, 0, 0, 0)" class="has-inline-color has-theme-palette-1-color">#answered</mark> questions</h2>
  </header>

  <div class="mp-hub-layout">
    <!-- LEFT SIDEBAR: FACETS -->
    <aside class="mp-hub-sidebar">
      <!-- SORT BY SECTION -->
      <div class="mp-facet-group">
        <h4>Sort By</h4>
        <div class="mp-facet-list">
          <a href="<?php echo esc_url(add_query_arg('sort', 'newest')); ?>"
            class="mp-facet-link <?php echo $current_sort === 'newest' ? 'active' : ''; ?>">
            Newest
          </a>
          <a href="<?php echo esc_url(add_query_arg('sort', 'trending')); ?>"
            class="mp-facet-link <?php echo $current_sort === 'trending' ? 'active' : ''; ?>">
            Trending 🔥
          </a>
        </div>
      </div>

      <!-- TOPICS SECTION -->
      <div class="mp-facet-group">
        <h4>Topics</h4>
        <div class="mp-facet-list">
          <a href="<?php echo esc_url($base_url); ?>" class="mp-facet-link <?php echo empty($current_cat) && empty($current_tag) ? 'active' : ''; ?>">
            All Queries
          </a>
          <?php
          // Use your helper function to get only relevant terms
          $categories = get_mp_terms_with_counts('category');
          foreach ($categories as $cat):
            $is_active = ($current_cat === $cat->slug);
            // Create a link that sets the category but keeps the current sort
            $link = add_query_arg(['category_name' => $cat->slug, 'tag' => false], $base_url);
            if ($current_sort !== 'newest') $link = add_query_arg('sort', $current_sort, $link);
          ?>
            <a href="<?php echo esc_url($link); ?>" class="mp-facet-link <?php echo $is_active ? 'active' : ''; ?>">
              <?php echo esc_html($cat->name); ?>
              <span class="mp-count"><?php echo $cat->post_count; ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- POPULAR TAGS SECTION -->
      <div class="mp-facet-group">
        <h4>Popular Tags</h4>
        <div class="mp-facet-list">
          <?php
          $tags = get_mp_terms_with_counts('post_tag');
          $tags = array_slice($tags, 0, 10);
          foreach ($tags as $tag):
            $is_active = ($current_tag === $tag->slug);
            $link = add_query_arg(['tag' => $tag->slug, 'category_name' => false], $base_url);
            if ($current_sort !== 'newest') $link = add_query_arg('sort', $current_sort, $link);
          ?>
            <a href="<?php echo esc_url($link); ?>" class="mp-facet-link <?php echo $is_active ? 'active' : ''; ?>">
              <span class="mp-tag-name">#<?php echo esc_html($tag->name); ?></span>
              <span class="mp-count"><?php echo $tag->post_count; ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </aside>

    <!-- RIGHT CONTENT: GRID -->
    <main class="mp-hub-main">
      <div class="mp-hub-grid">
        <?php if (have_posts()) : while (have_posts()) : the_post(); ?>
            <article class="mp-hub-card">
              <a class="mp-entry-link" href="<?php the_permalink(); ?>"></a>
              <?php if (has_post_thumbnail()) : the_post_thumbnail('medium_large', array('class' => 'mp-card-image')); ?>
              <?php else : ?>
                <div class="mp-placeholder">MilePoint Q&A</div>
              <?php endif; ?>
              <div class="mp-card-content">
                <span class="mp-card-cat"><?php echo get_the_category()[0]->name ?? 'General'; ?></span>
                <h3><?php the_title(); ?></h3>
                <p><?php echo wp_trim_words(get_the_excerpt(), 18); ?></p>
              </div>
            </article>
          <?php endwhile;
        else : ?>
          <div class="mp-no-results">No queries match your current filters.</div>
        <?php endif; ?>
      </div>
    </main>
  </div>
</div>

<?php get_footer(); ?>