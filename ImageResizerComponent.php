<?php

/**
 * Image Resizer Component
 * 
 * @author Matthew Dunham <matt@matthewdunham.com>
 */
class ImageResizerComponent extends Component {

	/**
	 * Maximum width of the resized image
	 * 
	 * @var int 
	 */
	public $maxHeight = 200;

	/**
	 * Maximum height for the resized image
	 * 
	 * @var int 
	 */
	public $maxWidth = 200;

	/**
	 * The default quality for resized images
	 * 
	 * @var int 
	 */
	public $quality = 80;

	/**
	 * Default amount of memory to allocate for resizing
	 * 
	 * @var string 
	 */
	public $allocateMemory = '100M';

	/**
	 * Contains information about the image
	 * 
	 * @access private
	 * @var array
	 */
	private $_imageInfo;

	/**
	 * File class used to wrap the image file
	 * 
	 * @access private
	 * @var File
	 */
	private $_imageFile;

	/**
	 * Resize an image to a specific size
	 * 
	 * Options you can pass
	 *   - maxWidth: The maximum width the image can have
	 *   - maxHeight: The maximum height the image can have
	 *   - allocatedMemory: If this is set it will temporarily increase the memory_limit of PHP
	 *   - output: The full path to where the new image should be saved if blank overwrites the source image
	 *   - cropRatio: This will automatically crop the photo based on a ration for example: 4:6
	 * 
	 * @param string $path Full path to source file
	 * @param array $options
	 * @return bool 
	 */
	public function resizeImage($path, array $options = array()) {
		extract($options);

		if ( ! isset($maxWidth)) {
			$maxWidth = $this->maxWidth;
		}

		if ( ! isset($maxHeight)) {
			$maxHeight = $this->maxHeight;
		}

		if ( ! isset($allocateMemory)) {
			$allocateMemory = $this->allocateMemory;
		}

		if ( ! isset($output)) {
			$output = $path;
		}

		if ( ! file_exists($path)) {
			throw new NotFoundException('The requested image ' . $path . ' was not found');
			return false;
		}

		if ( ! class_exists('File')) {
			App::uses('File', 'Utility');
		}

		$this->_imageInfo = getimagesize($path);
		$this->_imageFile = new File($path);

		if (substr($this->_imageInfo['mime'], 0, 6) != 'image/') {
			throw new InvalidArgumentException('File to resize must be an image');
			return false;
		}

		$width = $this->_imageInfo[0];
		$height = $this->_imageInfo[1];

		if ((file_exists($output) && ! is_writable($output)) || (file_exists(dirname($output)) && ! is_writable(dirname($output)))) {
			throw new Exception('Unable to write to your output file ' . $output);
			return false;
		}
		
		

		// If the image is smaller than both of maxHeight and maxWidth do nothing to it
		if (( ! $maxWidth && ! $maxHeight) || ( $maxWidth >= $width && $maxHeight >= $height)) {
			return copy($path, $output);
		}

		// Ratio cropping
		$offsetX = 0;
		$offsetY = 0;

		if (isset($cropRatio)) {
			$cropRatio = explode(':', $cropRatio);
			if (count($cropRatio) == 2) {
				$ratioComputed = $width / $height;
				$cropRatioComputed = (float) $cropRatio[0] / (float) $cropRatio[1];

				if ($ratioComputed < $cropRatioComputed) { // Image is too tall so we will crop the top and bottom
					$origHeight = $height;
					$height = $width / $cropRatioComputed;
					$offsetY = ($origHeight - $height) / 2;
				} else if ($ratioComputed > $cropRatioComputed) { // Image is too wide so we will crop off the left and right sides
					$origWidth = $width;
					$width = $height * $cropRatioComputed;
					$offsetX = ($origWidth - $width) / 2;
				}
			}
		}

		// Setting up the ratios needed for resizing. We will compare these below to determine how to
		// resize the image (based on height or based on width)
		$xRatio = $maxWidth / $width;
		$yRatio = $maxHeight / $height;

		// Resize the image based on width
		if ($xRatio * $height < $maxHeight) {
			$newHeight = ceil($xRatio * $height);
			$newWidth = $maxWidth;
		}

		// Resize the image based on height
		else {
			$newWidth = ceil($yRatio * $width);
			$newHeight = $maxHeight;
		}

		// Determine the quality of the output image
		$quality = (isset($quality)) ? (int) $quality : $this->quality;

		// We don't want to run out of memory
		ini_set('memory_limit', $allocateMemory);

		// Set up a blank canvas for our resized image (destination)
		$dst = imagecreatetruecolor($newWidth, $newHeight);

		// Set up the appropriate image handling functions based on the original image's mime type
		switch ($this->_imageInfo['mime']) {
			case 'image/gif':
				// We will be converting GIFs to PNGs to avoid transparency issues when resizing GIFs
				// This is maybe not the ideal solution, but IE6 can suck it
				$creationFunction = 'ImageCreateFromGif';
				$outputFunction = 'ImagePng';
				$mime = 'image/png'; // We need to convert GIFs to PNGs
				$doSharpen = FALSE;
				$quality = round(10 - ($quality / 10)); // We are converting the GIF to a PNG and PNG needs a compression level of 0 (no compression) through 9
				break;

			case 'image/x-png':
			case 'image/png':
				$creationFunction = 'ImageCreateFromPng';
				$outputFunction = 'ImagePng';
				$doSharpen = FALSE;
				$quality = round(10 - ($quality / 10)); // PNG needs a compression level of 0 (no compression) through 9
				break;

			default:
				$creationFunction = 'ImageCreateFromJpeg';
				$outputFunction = 'ImageJpeg';
				$doSharpen = TRUE;
				break;
		}

		// Read in the original image
		$src = $creationFunction($path);

		if (in_array($this->_imageInfo['mime'], array('image/gif', 'image/png'))) {
			// If this is a GIF or a PNG, we need to set up transparency
			imagealphablending($dst, false);
			imagesavealpha($dst, true);
		}

		if (false === imagecopyresampled($dst, $src, 0, 0, $offsetX, $offsetY, $newWidth, $newHeight, $width, $height)) {
			return false;
		}

		if ($doSharpen) {
			// Sharpen the image based on two things:
			//	(1) the difference between the original size and the final size
			//	(2) the final size
			$sharpness = $this->_findSharp($width, $newWidth);

			$sharpenMatrix = array(
				array(-1, -2, -1),
				array(-2, $sharpness + 12, -2),
				array(-1, -2, -1)
			);
			$divisor = $sharpness;
			$offset = 0;
			imageconvolution($dst, $sharpenMatrix, $divisor, $offset);
		}

		// Put the data of the resized image into a variable
		ob_start();
		$outputFunction($dst, null, $quality);
		$data = ob_get_contents();
		ob_end_clean();

		// Clean up the memory
		imagedestroy($src);
		imagedestroy($dst);
		
		return (bool) file_put_contents($output, $data);
	}

	/**
	 * Detects the sharpness of the photo
	 * 
	 * @access private
	 * @param float $orig
	 * @param float $final
	 * @return float 
	 */
	private function _findSharp($orig, $final) {
		$final = $final * (750.0 / $orig);
		$a = 52;
		$b = -0.27810650887573124;
		$c = .00047337278106508946;

		$result = $a + $b * $final + $c * $final * $final;

		return max(round($result), 0);
	}

}
