<div class="technical-document-item group lg:tw-flex tw-gap-x-3 lg:tw-gap-x-8 tw-shadow hover:tw-shadow-md tw-border-solid tw-border-gray-100 tw-border-[1px] tw-transition-all">
    <?php if (has_post_thumbnail()) : ?>
        <a href="<?php the_permalink(); ?>" class="technical-document-thumbnail tw-w-full lg:tw-w-1/2 tw-overflow-hidden max-lg:tw-rounded-t-l-[5px]">
            <?php the_post_thumbnail('medium', ['class' => 'tw-h-full tw-w-full tw-transition-transform tw-duration-300 group-hover:tw-scale-110']); ?>
        </a>
    <?php endif; ?>

    <div class="lg:tw-py-8 tw-py-3 lg:tw-pr-8 tw-pr-3 tw-w-full lg:tw-w-1/2 max-lg:tw-p-3">
        <h2 class="tw-text-[#171717] tw-uppercase tw-text-base tw-font-bold"><a  href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
        <div class="tw-text-[#525252] lg:tw-text-base tw-text-[14px] max-lg:*:tw-mb-0"><?php the_excerpt(); ?></div>
        <a class="tw-text-[#0284C7] tw-flex tw-items-center tw-gap-x-2 tw-transition-all" href="<?php the_permalink(); ?>">Xem chi tiết <span class="dashicons dashicons-arrow-right-alt"></span></a>
    </div>
</div>
