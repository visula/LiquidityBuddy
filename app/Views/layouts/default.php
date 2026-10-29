<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $this->renderSection("title") ?></title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <link rel="stylesheet" href="<?= base_url('assets/css/default.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/theme.css') ?>">
    <link rel="stylesheet" href="<?= base_url('assets/css/mobile.css') ?>">
    <?= $this->renderSection("styles") ?>
    <script>
        var base_url = "<?= base_url() ?>";
    </script>

    <!-- Bootstrap 5 JS Bundle -->
     <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-MrcW6ZMFYlzcLA8Nl+NtUVF0sA7MsXsP1UyJoMp4YLEuNSfAP+JcXn/tWtIaxVXM" crossorigin="anonymous"></script>
    <script type="text/javascript" charset="utf8" src="<?= base_url('assets/js/default.js') ?>"></script>
    <?= $this->renderSection("scripts") ?>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark lb-navbar">
        <div class="container-fluid">
            <a class="navbar-brand lb-brand" href="<?= base_url($Head['Link']) ?>">
                <span class="lb-brand-mark" aria-hidden="true">LB</span><span><?= esc($Head['Title']) ?></span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNavDropdown" aria-controls="navbarNavDropdown" aria-expanded="false" aria-label="Toggle navigation">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNavDropdown">
                <ul class="navbar-nav me-auto menu-item-list">
                    <?php
                        // Highlight the page being viewed: its own item, or a dropdown when one of its sub items is current
                        $currentID = $CurrentID ?? '';
                        $icon = static function ($name) {
                            return $name ? '<i class="bi bi-' . esc($name, 'attr') . '" aria-hidden="true"></i>' : '';
                        };
                    ?>
                    <?php foreach ($Head['MainList'] as $main_list_key => $value) : ?>
                        <?php if($value['Link'] == '') {
                            $subIDs = array_filter(array_column($value['SubMenu'] ?? [], 'ID'));
                            $isActive = ($currentID === $value['ID']) || in_array($currentID, $subIDs, true);
                        ?>
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle<?= $isActive ? ' active' : '' ?>" id="menu-<?= esc($value['ID'], 'attr') ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"<?= $isActive ? ' aria-current="page"' : '' ?>>
                                    <?= $icon($value['Icon'] ?? '') ?><span><?= esc($value['Title']) ?></span>
                                </a>
                                <ul class="dropdown-menu<?= ! empty($value['Scroll']) ? ' lb-dropdown-scroll' : '' ?>" aria-labelledby="menu-<?= esc($value['ID'], 'attr') ?>">
                                    <?php foreach($value['SubMenu'] as $sub_menu_key => $sub_menu_value){ ?>
                                        <?php if (! empty($sub_menu_value['Divider'])) { ?>
                                            <li><hr class="dropdown-divider"></li>
                                        <?php } elseif (isset($sub_menu_value['Header'])) { ?>
                                            <li><h6 class="dropdown-header"><?= esc($sub_menu_value['Header']) ?></h6></li>
                                        <?php } else {
                                            $isCurrent = ($currentID === ($sub_menu_value['ID'] ?? null));
                                        ?>
                                            <li><a class="dropdown-item<?= $isCurrent ? ' active' : '' ?>" href="<?= base_url($sub_menu_value['Link']) ?>"<?= $isCurrent ? ' aria-current="page"' : '' ?>><?= $icon($sub_menu_value['Icon'] ?? '') ?><span><?= esc($sub_menu_value['Title']) ?></span></a></li>
                                        <?php } ?>
                                    <?php } ?>
                                </ul>
                            </li>
                        <?php }else {
                            $isActive = ($currentID === $value['ID']);
                        ?>
                        <li class="nav-item">
                            <a class="nav-link<?= $isActive ? ' active' : '' ?>" id="<?= esc($value['ID'], 'attr') ?>" href="<?= base_url($value['Link']) ?>"<?= $isActive ? ' aria-current="page"' : '' ?>><?= $icon($value['Icon'] ?? '') ?><span><?= esc($value['Title']) ?></span></a>
                        </li>
                        <?php } ?>
                    <?php endforeach; ?>
                </ul>
                <ul class="navbar-nav ms-lg-auto">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle lb-profile" id="menu-profile" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="lb-avatar" aria-hidden="true"><?= esc($Head['Profile']['Initials'] ?? 'U') ?></span>
                            <span><?= esc($Head['Profile']['Title']) ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="menu-profile">
                            <?php if (! empty($Head['Profile']['FullName'])) { ?>
                                <li><span class="dropdown-item-text small text-muted">Signed in as <strong class="text-body"><?= esc($Head['Profile']['FullName']) ?></strong></span></li>
                                <li><hr class="dropdown-divider"></li>
                            <?php } ?>
                            <?php foreach($Head['Profile']['SubMenu'] as $key => $profile) { ?>
                                <li><a class="dropdown-item" href="<?= base_url($profile['Link']) ?>"><?= $icon($profile['Icon'] ?? '') ?><span><?= esc($profile['Title']) ?></span></a></li>
                            <?php } ?>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <main class="lb-page">
        <div class="container lb-page-header">
            <h1><?= trim($this->renderSection("header")) ?></h1>
        </div>
        <?= $this->renderSection("content") ?>
    </main>
</body>
</html>