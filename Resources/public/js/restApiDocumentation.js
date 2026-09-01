/*
 * Sylphen Data Bridge for OpenDXP
 * Copyright (c) Sylphen GmbH — https://sylphen.com
 * Based on Blackbit Data Director — Copyright (c) Blackbit digital Commerce GmbH
 * SPDX-License-Identifier: GPL-3.0-or-later
 */


window.onload = function () {
    var settings = {
        url: document.body.dataset.url,
        dom_id: '#swagger-ui',
        deepLinking: true,
        validatorUrl: null,
        presets: [
            SwaggerUIBundle.presets.apis,
            SwaggerUIStandalonePreset
        ],
        layout: "StandaloneLayout"
    };

    if(document.body.dataset.dataportId) {
        settings.docExpansion = 'full';
    }
    const ui = SwaggerUIBundle(settings);

    // End Swagger UI call region
    window.ui = ui
}