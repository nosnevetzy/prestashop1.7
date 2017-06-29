<?php
/**
 * 2007-2017 PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade PrestaShop to newer
 * versions in the future. If you wish to customize PrestaShop for your
 * needs please refer to http://www.prestashop.com for more information.
 *
 * @author    PrestaShop SA <contact@prestashop.com>
 * @copyright 2007-2017 PrestaShop SA
 * @license   http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * International Registered Trademark & Property of PrestaShop SA
 */

class FileUploaderCore
{
    protected $allowedExtensions = array();

    /** @var QqUploadedFileXhr|QqUploadedFileForm|false */
    protected $file;
    protected $sizeLimit;

    public function __construct(array $allowedExtensions = array(), $sizeLimit = 10485760)
    {
        $allowedExtensions = array_map('strtolower', $allowedExtensions);

        $this->allowedExtensions = $allowedExtensions;
        $this->sizeLimit = $sizeLimit;

        if (isset($_GET['qqfile'])) {
            $this->file = new QqUploadedFileXhr();
        } elseif (isset($_FILES['qqfile'])) {
            $this->file = new QqUploadedFileForm();
        } else {
            $this->file = false;
        }
    }

    protected function toBytes($str)
    {
        $val = trim($str);
        $last = strtolower($str[strlen($str) - 1]);
        switch ($last) {
            case 'g': $val *= 1024;
            case 'm': $val *= 1024;
            case 'k': $val *= 1024;
        }
        return $val;
    }

    /**
     * Returns array('success'=>true) or array('error'=>'error message')
     */
    public function handleUpload()
    {
        if (!$this->file) {
            return array('error' => Context::getContext()->getTranslator()->trans('No files were uploaded.', array(), 'Admin.Notifications.Error'));
        }

        $size = $this->file->getSize();

        if ($size == 0) {
            return array('error' => Context::getContext()->getTranslator()->trans('Source file does not exist or is empty.', array(), 'Admin.Notifications.Error'));
        }
        if ($size > $this->sizeLimit) {
            return array('error' => Context::getContext()->getTranslator()->trans('The uploaded file is too large.', array(), 'Admin.Notifications.Error'));
        }

        $pathinfo = pathinfo($this->file->getName());
        $these = implode(', ', $this->allowedExtensions);
        if (!isset($pathinfo['extension'])) {
            return array('error' => Context::getContext()->getTranslator()->trans('File has an invalid extension, it should be one of these: %s.', array($these), 'Admin.Notifications.Error'));
        }
        $ext = $pathinfo['extension'];
        if ($this->allowedExtensions && !in_array(strtolower($ext), $this->allowedExtensions)) {
            return array('error' => Context::getContext()->getTranslator()->trans('File has an invalid extension, it should be one of these: %s.', array($these), 'Admin.Notifications.Error'));
        }

        return $this->file->save();
    }
}

class QqUploadedFileForm
{
    /**
     * Save the file to the specified path
     * @return bool TRUE on success
     */
    public function save()
    {
        $product = new Product($_GET['id_product']);
        if (!Validate::isLoadedObject($product)) {
            return array('error' => Context::getContext()->getTranslator()->trans('Cannot add image because product creation failed.', array(), 'Admin.Catalog.Notification'));
        } else {
            $image = new Image();
            $image->id_product = (int)$product->id;
            $image->position = Image::getHighestPosition($product->id) + 1;
            $legends = Tools::getValue('legend');
            if (is_array($legends)) {
                foreach ($legends as $key => $legend) {
                    if (Validate::isGenericName($legend)) {
                        $image->legend[(int)$key] = $legend;
                    } else {
                        return array('error' => Context::getContext()->getTranslator()->trans('Error on image caption: "%1s" is not a valid caption.', array(Tools::safeOutput($legend)), 'Admin.Catalog.Notification'));
                    }
                }
            }
            if (!Image::getCover($image->id_product)) {
                $image->cover = 1;
            } else {
                $image->cover = 0;
            }

            if (($validate = $image->validateFieldsLang(false, true)) !== true) {
                return array('error' => $validate);
            }
            if (!$image->add()) {
                return array('error' => Context::getContext()->getTranslator()->trans('Error while creating additional image', array(), 'Admin.Catalog.Notification'));
            } else {
                return $this->copyImage($product->id, $image->id);
            }
        }
    }

    public function copyImage($id_product, $id_image, $method = 'auto')
    {
        $image = new Image($id_image);
        if (!$new_path = $image->getPathForCreation()) {
            return array('error' => Context::getContext()->getTranslator()->trans('An error occurred while attempting to create a new folder.', array(), 'Admin.Notifications.Error'));
        }
        if (!($tmpName = tempnam(_PS_TMP_IMG_DIR_, 'PS')) || !move_uploaded_file($_FILES['qqfile']['tmp_name'], $tmpName)) {
            return array('error' => Context::getContext()->getTranslator()->trans('An error occurred while uploading the image.', array(), 'Admin.Notifications.Error'));
        } elseif (!ImageManager::resize($tmpName, $new_path.'.'.$image->image_format)) {
            return array('error' => Context::getContext()->getTranslator()->trans('An error occurred while copying the image.', array(), 'Admin.Notifications.Error'));
        } elseif ($method == 'auto') {
            $imagesTypes = ImageType::getImagesTypes('products');
            foreach ($imagesTypes as $imageType) {
                if (!ImageManager::resize($tmpName, $new_path.'-'.stripslashes($imageType['name']).'.'.$image->image_format, $imageType['width'], $imageType['height'], $image->image_format)) {
                    return array('error' => Context::getContext()->getTranslator()->trans('An error occurred while copying this image: %s', array(stripslashes($imageType['name'])), 'Admin.Notifications.Error'));
                }
            }
        }
        unlink($tmpName);
        Hook::exec('actionWatermark', array('id_image' => $id_image, 'id_product' => $id_product));

        if (!$image->update()) {
            return array('error' => Context::getContext()->getTranslator()->trans('Error while updating the status.', array(), 'Admin.Notifications.Error'));
        }
        $img = array('id_image' => $image->id, 'position' => $image->position, 'cover' => $image->cover, 'name' => $this->getName(), 'legend' => $image->legend);
        return array('success' => $img);
    }

    public function getName()
    {
        return $_FILES['qqfile']['name'];
    }

    public function getSize()
    {
        return $_FILES['qqfile']['size'];
    }
}
/**
 * Handle file uploads via XMLHttpRequest
 */
class QqUploadedFileXhr
{
    /**
     * Save the file to the specified path
     * @return bool TRUE on success
     */
    public function upload($path)
    {
        $input = fopen('php://input', 'r');
        $target = fopen($path, 'w');

        $realSize = stream_copy_to_stream($input, $target);
        if ($realSize != $this->getSize()) {
            return false;
        }

        fclose($input);
        fclose($target);

        return true;
    }

    public function save()
    {
        $product = new Product($_GET['id_product']);
        if (!Validate::isLoadedObject($product)) {
            return array('error' => Context::getContext()->getTranslator()->trans('Cannot add image because product creation failed.', array(), 'Admin.Catalog.Notification'));
        } else {
            $image = new Image();
            $image->id_product = (int)($product->id);
            $image->position = Image::getHighestPosition($product->id) + 1;
            $legends = Tools::getValue('legend');
            if (is_array($legends)) {
                foreach ($legends as $key => $legend) {
                    if (Validate::isGenericName($legend)) {
                        $image->legend[(int)$key] = $legend;
                    } else {
                        return array('error' => Context::getContext()->getTranslator()->trans('Error on image caption: "%1s" is not a valid caption.', array(Tools::safeOutput($legend)), 'Admin.Notifications.Error'));
                    }
                }
            }
            if (!Image::getCover($image->id_product)) {
                $image->cover = 1;
            } else {
                $image->cover = 0;
            }

            if (($validate = $image->validateFieldsLang(false, true)) !== true) {
                return array('error' => $validate);
            }
            if (!$image->add()) {
                return array('error' => Context::getContext()->getTranslator()->trans('Error while creating additional image', array(), 'Admin.Catalog.Notification'));
            } else {
                return $this->copyImage($product->id, $image->id);
            }
        }
    }

    public function copyImage($id_product, $id_image, $method = 'auto')
    {
        $image = new Image($id_image);
        if (!$new_path = $image->getPathForCreation()) {
            return array('error' => Context::getContext()->getTranslator()->trans('An error occurred while attempting to create a new folder.', array(), 'Admin.Notifications.Error'));
        }
        if (!($tmpName = tempnam(_PS_TMP_IMG_DIR_, 'PS')) || !$this->upload($tmpName)) {
            return array('error' => Context::getContext()->getTranslator()->trans('An error occurred while uploading the image.', array(), 'Admin.Notifications.Error'));
        } elseif (!ImageManager::resize($tmpName, $new_path.'.'.$image->image_format)) {
            return array('error' => Context::getContext()->getTranslator()->trans('An error occurred while uploading the image.', array(), 'Admin.Notifications.Error'));
        } elseif ($method == 'auto') {
            $imagesTypes = ImageType::getImagesTypes('products');
            foreach ($imagesTypes as $imageType) {
                if (!ImageManager::resize($tmpName, $new_path.'-'.stripslashes($imageType['name']).'.'.$image->image_format, $imageType['width'], $imageType['height'], $image->image_format)) {
                    return array('error' => Context::getContext()->getTranslator()->trans('An error occurred while copying this image: %s', array(stripslashes($imageType['name'])), 'Admin.Notifications.Error'));
                }
            }
        }
        unlink($tmpName);
        Hook::exec('actionWatermark', array('id_image' => $id_image, 'id_product' => $id_product));

        if (!$image->update()) {
            return array('error' => Context::getContext()->getTranslator()->trans('Error while updating the status.', array(), 'Admin.Notifications.Error'));
        }
        $img = array('id_image' => $image->id, 'position' => $image->position, 'cover' => $image->cover, 'name' => $this->getName(), 'legend' => $image->legend);
        return array('success' => $img);
    }

    public function getName()
    {
        return $_GET['qqfile'];
    }

    public function getSize()
    {
        if (isset($_SERVER['CONTENT_LENGTH']) || isset($_SERVER['HTTP_CONTENT_LENGTH'])) {
            if (isset($_SERVER['HTTP_CONTENT_LENGTH'])) {
                return (int)$_SERVER['HTTP_CONTENT_LENGTH'];
            } else {
                return (int)$_SERVER['CONTENT_LENGTH'];
            }
        }
        return false;
    }
}
