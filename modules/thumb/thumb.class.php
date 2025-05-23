<?php
/**
 * Thumbnail builder
 *
 * @package MajorDoMo
 * @author Serge Dzheigalo <jey@tut.by> http://smartliving.ru/
 * @version 0.2
 */

//
class thumb extends module
{

// --------------------------------------------------------------------

    function __construct()
    {

        // setting module name
        $this->name = "thumb";
        $this->title = "<#LANG_MODULE_THUMB#>";
        $this->module_category = "<#LANG_SECTION_SYSTEM#>";

        $this->checkInstalled();

    }


// --------------------------------------------------------------------

    function run()
    {

        //if (preg_match('/~/', $this->src)) {
        // $this->src=preg_replace('/\/~(.+?)\//', '/', $this->src);
        //}


        if (isset($this->live)) {
            $out['LIVE'] = $this->live;
        }

        if (isset($this->slideshow)) {
            $out['SLIDESHOW'] = $this->slideshow;
        }

        if (isset($this->userpassword)) {
            $this->userpassword = processTitle($this->userpassword);
            $tmp = explode(':', $this->userpassword);
            $this->username = $tmp[0];
            $this->password = $tmp[1];
        }

        if (isset($this->url)) {
            $this->url = processTitle($this->url);
            $this->username = processTitle($this->username);
            $this->password = processTitle($this->password);
            $filename = 'thumb_' . md5($this->url);
            if (preg_match('/\.cgi$/is', $filename)) {
                $filename = str_replace('.cgi', '.jpg', $filename);
            }
            $this->src = ROOT . 'cms/cached/' . $filename;
            $this->src_def = urlencode('/cms/cached/' . $filename);

        } else {
            preg_match('/(.*)?\/.*$/', $_SERVER['PHP_SELF'], $match);
            $this->src_def = urlencode('http://' . $_SERVER['SERVER_NAME'] . $match[1] . $this->src);
        }

        $out['REQUESTED'] = $this->src;


        if ((isset($this->src) && file_exists($this->src)) || isset($this->url)) {
            //$lst=GetImageSize($this->src);
            if (isset($lst)) {
                $out['REAL_WIDTH'] = $lst[0];
                $out['REAL_HEIGHT'] = $lst[1];
            }
            if (isset($this->url)) $out['URL'] = base64_encode($this->url);
            if (isset($this->transport)) $out['TRANSPORT'] = urldecode($this->transport);

            if (isset($this->username)) $out['USERNAME'] = urlencode($this->username);
            if (isset($this->password)) $out['PASSWORD'] = urlencode($this->password);

            $out['UNIQ'] = rand(1, time());
            if (isset($this->width)) $out['WIDTH'] = $this->width;
            if (isset($this->height)) $out['HEIGHT'] = $this->height;
            if (isset($this->max_height)) $out['MAX_HEIGHT'] = $this->max_height;
            if (isset($this->max_width)) $out['MAX_WIDTH'] = $this->max_width;
            if (isset($this->close)) $out['CLOSE'] = htmlspecialchars($this->close);
            /*
            $out['BGCOLOR']=(($this->bgcolor[0]='#')?substr($this->bgcolor,1):$this->bgcolor);
            $out['COLOR']=(($this->color[0]='#')?substr($this->color,1):$this->color);
            */
            if (isset($this->enlarge)) $out['ENLARGE'] = (int)($this->enlarge);
            if (isset($this->src)) $out['SRC'] = urlencode($this->src);
            if (isset($this->src_def)) $out['SRC_REAL'] = $this->src_def;
        }


        $this->data = $out;
        $p = new parser(DIR_TEMPLATES . $this->name . "/" . $this->name . ".html", $this->data, $this);
        $this->result = $p->result;
    }


    function resizeImage($filename, $new_width = 0, $new_height = 0)
    {

        if (file_exists($filename)) {

            $lst = GetImageSize($filename);
            $image_width = $lst[0];
            $image_height = $lst[1];
            $image_format = $lst[2];

            $type = 0;

            switch ($type) {
                case 0:
                    if (($new_width != 0) && ($new_width < $image_width)) {
                        $image_height = (int)($image_height * ($new_width / $image_width));
                        $image_width = $new_width;
                    }
                    if (($new_height != 0) && ($new_height < $image_height)) {
                        $image_width = (int)($image_width * ($new_height / $image_height));
                        $image_height = $new_height;
                    }
                    break;

                case 1:
                    $image_width = $new_width;
                    $image_height = $image_height;
                    break;
            }


            if ($image_format == 1) {
                $old_image = imagecreatefromgif($filename);
            } elseif ($image_format == 2) {
                $old_image = imagecreatefromjpeg($filename);
            } elseif ($image_format == 3) {
                $old_image = imagecreatefrompng($filename);
            } else {
                return 0;
            }

            $new_image = imageCreateTrueColor($image_width, $image_height);
            $white = ImageColorAllocate($new_image, 255, 255, 255);
            ImageFill($new_image, 0, 0, $white);

            /*imageCopyResized*/
            imagecopyresampled($new_image, $old_image, 0, 0, 0, 0, $image_width, $image_height, imageSX($old_image), imageSY($old_image));

            //   Save to file
            imageJpeg($new_image, $filename);
            return 1;

        } else {
            return 0;
        }

    }


}

