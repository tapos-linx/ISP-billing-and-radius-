package com.example.data.model

data class DeploymentStage(
    val stageNumber: Int,
    val daysRange: String,
    val title: String,
    val subtitle: String,
    val targetSla: String,
    val summary: String,
    val technologies: List<String>,
    val artifacts: List<StageArtifact>,
    val verificationSteps: List<VerificationStep>
)

data class StageArtifact(
    val id: String,
    val title: String,
    val fileName: String,
    val language: String,
    val description: String,
    val codeContent: String
)

data class VerificationStep(
    val stepNumber: Int,
    val title: String,
    val powerShellCommand: String,
    val expectedOutput: String,
    val notes: String
)
