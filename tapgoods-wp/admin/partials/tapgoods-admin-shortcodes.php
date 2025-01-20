<?php


$shortcodes_info = Tapgoods_Shortcodes::get_shortcodes();

?>
<div class="container">


    <h2 class="mb-4">Shortcodes</h2>

    <!-- Row 1 -->
	<div class="row mb-3">
	<!-- Column for the title -->
	<div class="col-md-2">
		<h3 class="fw-bold active" style="color: var(--tg-blue);">Show Inventory</h3>

	</div>
	
	<!-- Column for the content and preview -->
	<div class="col-md-10">
		<div class="row">
			<!-- Content -->
			<div class="col-md-8">
				<form>
					<div class="input-group mb-3">
						<input type="text" id="tapgoods-inventory-input" class="form-control" disabled value="[tapgoods-inventory]">
						<button type="button" onClick="copyText('tapgoods-inventory-input')" 
								data-bs-toggle="tooltip" data-bs-placement="bottom" 
								data-bs-title="Copy to clipboard" 
								class="btn btn-outline-secondary">
							<span class="dashicons dashicons-admin-page"></span>
						</button>
					</div>
				</form>
				<p class="text-muted">
					Use this shortcode to add inventory to any page. Example pages could be a SHOP PAGE, 
					or a page you want to show off specifically tagged inventory.
				</p>
				<p><strong>Shortcode Modifiers:</strong></p>
				<ul class="text-muted">
					<li>Filter by Tags: <code>[tapgoods-inventory tags="your-tag"]</code></li>
					<li>Hide Inventory Filters: <code>[tapgoods-inventory show_filters="false"]</code></li>
					<li>Hide Item Pricing: <code>[tapgoods-inventory show_pricing="false"]</code></li>
					<li>Combine Modifiers: <code>[tapgoods-inventory tags="Blush-Wedding" show_filters="false"]</code></li>
				</ul>
			</div>
			
			<!-- Preview -->
			<div class="col-md-4 text-center">
			<img src="<?php echo esc_url(plugin_dir_url(dirname(__FILE__, 2)) . 'assets/img/tapgoods-inventory.png'); ?>" alt="Preview" class="img-fluid border rounded">
			</div>
		</div>
	</div>
</div>
	<hr class="my-4">

    <!-- Row 2 -->

	<div class="row mb-3">
	<!-- Column for the title -->
	<div class="col-md-2">
		<h3 class="fw-bold active" style="color: var(--tg-blue);">Select Location</h3>

	</div>
	
	<!-- Column for the content and preview -->
	<div class="col-md-10">
		<div class="row">
			<!-- Content -->
			<div class="col-md-8">
				<form>
					<div class="input-group mb-3">
						<input type="text" id="tapgoods-location-select-input" class="form-control" disabled value="[tapgoods-location-select]">
						<button type="button" onClick="copyText('tapgoods-location-select-input')" 
								data-bs-toggle="tooltip" data-bs-placement="bottom" 
								data-bs-title="Copy to clipboard" 
								class="btn btn-outline-secondary">
							<span class="dashicons dashicons-admin-page"></span>
						</button>
					</div>
				</form>
				<p class="text-muted">Add a LOCATION SELECTOR dropdown.</p>
			</div>
			
			<!-- Preview -->
			<div class="col-md-4 text-center">
				<select class="form-select rounded-pill">
                    <option>Location</option>
                </select>
			</div>
		</div>
	</div>
</div>


<hr class="my-4">
<!-- Row 3 -->

<div class="row mb-3">
	<!-- Column for the title -->
	<div class="col-md-2">
		<h3 class="fw-bold active" style="color: var(--tg-blue);">Cart Button</h3>

	</div>
	
	<!-- Column for the content and preview -->
	<div class="col-md-10">
		<div class="row">
			<!-- Content -->
			<div class="col-md-8">
				<form>
					<div class="input-group mb-3">
						<input type="text" id="tapgoods-cart-input" class="form-control" disabled value="[tapgoods-cart]">
						<button type="button" onClick="copyText('tapgoods-cart-input')" 
								data-bs-toggle="tooltip" data-bs-placement="bottom" 
								data-bs-title="Copy to clipboard" 
								class="btn btn-outline-secondary">
							<span class="dashicons dashicons-admin-page"></span>
						</button>
					</div>
				</form>
				<p class="text-muted">
				Add a CART icon to your navigation.
				</p>

			</div>
			
			<!-- Preview -->
			<div class="col-md-4 text-center">
			<svg class="tg-primary" width="30" height="23" viewBox="0 0 30 23" xmlns="http://www.w3.org/2000/svg">
					<path fill-rule="evenodd" clip-rule="evenodd" d="M6.07816 0.807775L7.03594 3.85529H28.8805C28.9977 3.8538 29.1144 3.87089 29.2262 3.90597C29.8084 4.08852 30.1319 4.70693 29.9488 5.28725L26.6334 16.2289C26.4773 16.6856 26.0492 16.9947 25.5651 17H9.61455C9.13636 17.0021 8.71503 16.6871 8.5831 16.2289L4.19946 2.20302H0V0H5.04672C5.53101 0.0147633 5.94916 0.342272 6.07816 0.807775ZM12.5531 18.0006C11.1727 17.9713 10.0299 19.0666 10.0006 20.447C9.97129 21.8274 11.0666 22.9701 12.447 22.9994C13.8273 23.0287 14.9701 21.9335 14.9995 20.5531C15 20.5267 15.0002 20.5003 14.9998 20.474C15.0146 19.1227 13.9311 18.0154 12.5798 18.0007L12.5531 18.0006ZM21.5797 18.0013C20.1996 17.9573 19.0453 19.0403 19.0013 20.4203C18.9573 21.8003 20.0403 22.9547 21.4204 22.9987C22.8004 23.0427 23.9547 21.9597 23.9987 20.5796C23.9998 20.5447 24.0002 20.5097 23.9999 20.4747C24.0146 19.1234 22.9312 18.0161 21.58 18.0013H21.5797Z" fill="currentColor" />
				</svg>
			</div>
		</div>
	</div>
</div>

<hr class="my-4">
<!-- Row 4 -->

<div class="row mb-3">
	<!-- Column for the title -->
	<div class="col-md-2">
		<h3 class="fw-bold active" style="color: var(--tg-blue);">Sign In Link</h3>

	</div>
	
	<!-- Column for the content and preview -->
	<div class="col-md-10">
		<div class="row">
			<!-- Content -->
			<div class="col-md-8">
				<form>
					<div class="input-group mb-3">
						<input type="text" id="tapgoods-sign-in-input" class="form-control" disabled value="[tapgoods-sign-in]">
						<button type="button" onClick="copyText('tapgoods-sign-in]-input')" 
								data-bs-toggle="tooltip" data-bs-placement="bottom" 
								data-bs-title="Copy to clipboard" 
								class="btn btn-outline-secondary">
							<span class="dashicons dashicons-admin-page"></span>
						</button>
					</div>
				</form>
				<p class="text-muted">
				Add a SIGN IN icon to your navigation.
				</p>
			</div>
			
			<!-- Preview -->
			<div class="col-md-4 text-center">
			<a href="#" class="text-decoration-underline">Sign In</a>
			</div>
		</div>
	</div>
</div>


<hr class="my-4">
<!-- Row 5 -->

<div class="row mb-3">
	<!-- Column for the title -->
	<div class="col-md-2">
		<h3 class="fw-bold active" style="color: var(--tg-blue);">Sign Up Link</h3>

	</div>
	
	<!-- Column for the content and preview -->
	<div class="col-md-10">
		<div class="row">
			<!-- Content -->
			<div class="col-md-8">
				<form>
					<div class="input-group mb-3">
						<input type="text" id="tapgoods-sign-up-input" class="form-control" disabled value="[tapgoods-sign-up]">
						<button type="button" onClick="copyText('tapgoods-sign-up-input')" 
								data-bs-toggle="tooltip" data-bs-placement="bottom" 
								data-bs-title="Copy to clipboard" 
								class="btn btn-outline-secondary">
							<span class="dashicons dashicons-admin-page"></span>
						</button>
					</div>
				</form>
				<p class="text-muted">
				Add a SIGN UP icon to your navigation.
				</p>
			</div>
			
			<!-- Preview -->
			<div class="col-md-4 text-center">
				<a href="#" class="text-decoration-underline">Sign Up</a>
			</div>
		</div>
	</div>
</div>
</div>


<script>
function copyText(inputId) {
    const input = document.getElementById(inputId);
    if (input) {
        navigator.clipboard.writeText(input.value).then(() => {
            console.log('Copied to clipboard: ' + input.value);
        }).catch(err => {
            console.error('Failed to copy: ', err);
        });
    } else {
        console.error('Input element not found with ID: ' + inputId);
    }
}


</script>










