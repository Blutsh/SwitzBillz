/**
 * Copyright since 2007 PrestaShop SA and Contributors
 * PrestaShop is an International Registered Trademark & Property of PrestaShop SA
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@prestashop.com so we can send you a copy immediately.
 *
 * @author    PrestaShop SA and Contributors <contact@prestashop.com>
 * @copyright Since 2007 PrestaShop SA and Contributors
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

document.addEventListener("DOMContentLoaded", function() {
    var previewButton = document.getElementById("previewQrCodeButton");
    var submitButton = document.getElementById("submitSwitzBillzModule");
    var formValid = false;

    var referenceField = document.querySelector("input[name='SWITZBILLZ_REFERENCES']").closest(".form-group");
    var referenceTypeSelect = document.querySelector("select[name='SWITZBILLZ_REFERENCE_TYPE']");

    var additionalInfoField = document.querySelector("input[name='SWITZBILLZ_ADDITIONAL_INFO_CUSTOM']").closest(".form-group");
    var additionalInfoSelect = document.querySelector("select[name='SWITZBILLZ_ADDITIONAL_INFO']");

    // Create the preview container div
    var previewContainer = document.createElement("div");
    previewContainer.id = "qrCodePreviewContainer";
    previewContainer.style.marginTop = "20px";
    previewContainer.style.display = "flex";
    previewContainer.style.justifyContent = "center";

    // Append the preview container after the last form-group div
    var formWrapper = document.querySelector(".form-wrapper");
    formWrapper.appendChild(previewContainer);

    function toggleAdditionalInfoField() {
        if (additionalInfoSelect.value === "custom") {
            additionalInfoField.style.display = "block";
        } else {
            additionalInfoField.style.display = "none";
        }
    }

    function toggleReferenceField() {
        if (referenceTypeSelect.value === "custom_reference") {
            referenceField.style.display = "block";
        } else {
            referenceField.style.display = "none";
        }
    }

    function previewQrCode() {
        previewContainer.innerHTML = ""; // Clear the preview container
        console.log("Previewing QR code...");
        var link = previewQrCodeLink;
        var form = document.querySelector("#module_form");
        var formData = $(form).serialize(); // Serialize form data

        $.ajax({
            type: "POST",
            dataType: "json",
            url: link,
            cache: false,
            data: formData + "&ajax=true", // Include form data and ajax=true in the request
            success: function(data, status) {
                console.log(status);
                console.log(data);
                var previewContainer = document.getElementById("qrCodePreviewContainer");
                if (data.status === "success") {
                    previewContainer.innerHTML = data.qrCodeHtml; // Show QR Code HTML
                    formValid = true;
                    submitButton.disabled = false; // Enable submit button
                } else {
                    formValid = false;
                    submitButton.disabled = true; // Disable submit button
                    for (var i = 0; i < data.errors.length; i++) {
                        previewContainer.innerHTML += '<div class="alert alert-danger">Error: ' + data.errors[i] + '</div><br>';
                    }
                }
            },
            error: function(xhr, status, error) {
                console.log(status);
                console.log(error);
                console.log(xhr.responseText);
                formValid = false;
                submitButton.disabled = true; // Disable submit button
            }
        });
    }

    additionalInfoSelect.addEventListener("change", toggleAdditionalInfoField);
    referenceTypeSelect.addEventListener("change", toggleReferenceField);
    previewButton.addEventListener("click", function(e) {
        e.preventDefault();
        previewQrCode();
    });

    toggleReferenceField();
    toggleAdditionalInfoField();

    // Disable submit button initially
    submitButton.disabled = true;

    // Prevent form submission if preview button hasn't been clicked
    document.getElementById("module_form").addEventListener("submit", function(e) {
        if (!formValid) {
            e.preventDefault();
            alert("Please preview the QR Code before saving the form.");
        }
    });
});
