<?php
function my_is_request($type)
{
  switch ($type) {
    case "admin":
      return is_admin();
    case "ajax":
      return defined("DOING_AJAX");
    case "cron":
      return defined("DOING_CRON");
    case "frontend":
      return (!is_admin() || defined("DOING_AJAX")) &&
        !defined("DOING_CRON") &&
        !my_is_rest_api_request();
  }
}

function my_is_rest_api_request()
{
  if (empty($_SERVER["REQUEST_URI"])) {
    return false;
  }

  $rest_prefix = trailingslashit(rest_get_url_prefix());
  $is_rest_api_request =
    false !== strpos($_SERVER["REQUEST_URI"], $rest_prefix); // phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

  return $is_rest_api_request;
}
