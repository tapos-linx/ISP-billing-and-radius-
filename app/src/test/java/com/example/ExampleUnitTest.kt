package com.example

import com.example.data.model.computeCgnatPortBlock
import com.example.data.model.computeCitusShardId
import com.example.data.network.CloudRunApiClient
import org.junit.Assert.*
import org.junit.Test

class ExampleUnitTest {
    @Test
    fun citusShardComputation_isDeterministicWithin128Shards() {
        val shardA = computeCitusShardId("tapos_test")
        val shardB = computeCitusShardId("tapos_test")
        assertEquals(shardA, shardB)
        assertTrue(shardA in 0..127)
    }

    @Test
    fun cgnatPortBlock_allocatesNonOverlappingRfc6888Ranges() {
        val block0 = computeCgnatPortBlock(0)
        val block1 = computeCgnatPortBlock(1)
        assertEquals(1024 to 1535, block0)
        assertEquals(1536 to 2047, block1)
    }

    @Test
    fun cloudRunClient_requiresHttpsUrls() {
        val client = CloudRunApiClient()
        assertTrue(client.isValidHttpsUrl("https://isp-billing-api.a.run.app/"))
        assertFalse(client.isValidHttpsUrl("http://insecure.example.com"))
        assertFalse(client.isValidHttpsUrl(""))
    }
}
