<?php
global $post;
$images = apply_filters(
	'tg_inventory_single_images',
	get_post_meta( $post->ID, 'tg_pictures', true )
);
?>
<section class="images col mb-4">
<?php if ( ! empty( $images ) ) : ?>
	<div id="tg-carousel" class="carousel slide mb-3">
		<div class="carousel-inner">
		<?php $img_coutner = 0; ?>
		<?php foreach ( $images as $image ) : ?>
			<?php $img_tag = Tapgoods_Public::get_img_tag( $image['imgixUrl'], 500, 280, 'd-block w-100 h-auto', '' ); ?>
			<div class="carousel-item<?php echo ( 0 === $img_coutner ) ? ' active' : ''; ?>">
				<?php echo wp_kses( $img_tag, 'post' ); ?>
			</div>
			<?php ++$img_coutner; ?>
		<?php endforeach; ?>
		</div>
		<button class="carousel-control-prev" type="button" data-bs-target="#tg-carousel" data-bs-slide="prev">
			<span class="carousel-control-prev-icon" aria-hidden="true"></span>
			<span class="visually-hidden">Previous</span>
		</button>
		<button class="carousel-control-next" type="button" data-bs-target="#tg-carousel" data-bs-slide="next">
			<span class="carousel-control-next-icon" aria-hidden="true"></span>
			<span class="visually-hidden">Next</span>
		</button>				
	</div>
	<?php if ( count( $images ) > 1 ) : ?> 
		<?php $img_coutner = 0; ?>
	<div class="thumbs">
		<?php foreach ( $images as $image ) : ?>
			<?php $img_tag = Tapgoods_Public::get_img_tag( $image['imgixUrl'], 160, 90, 'thumbnail' ); ?>
				<button class="thumbnail-btn me-2<?php echo ( 0 === $img_coutner ) ? ' active' : ''; ?>" data-cindex=<?php echo esc_attr( $img_coutner ); ?>>
					<?php echo wp_kses( $img_tag, 'post' ); ?>
				</button>
			<?php ++$img_coutner; ?>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>
<?php endif; ?>
</section>
