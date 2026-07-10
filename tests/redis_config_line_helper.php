<?php
function ccm_tools_redis_config_line(string $constant, $value): string {
    return "define('" . $constant . "', " . var_export($value, true) . ");";
}
